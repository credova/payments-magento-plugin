<?php

namespace PublicSquare\Payments\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PublicSquare\Payments\Logger\Logger;

/**
 * Marks a cart as a subscription renewal, so PublicSquare charges it as a recurring payment of the first order.
 * QuotePaymentPlugin stops clients from setting the mark themselves.
 */
class SubscriptionRenewal
{
    /** Holds ['paymentId' => ..., 'cardId' => ...] of the initial payment. */
    public const INITIAL_PAYMENT_KEY = 'psq_initial_payment';

    /**
     * @var CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var PaymentTokenManagementInterface
     */
    private $tokenManagement;

    /**
     * @var Logger
     */
    private $logger;

    public function __construct(
        CartRepositoryInterface $quoteRepository,
        PaymentTokenManagementInterface $tokenManagement,
        Logger $logger
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->tokenManagement = $tokenManagement;
        $this->logger = $logger;
    }

    /**
     * Marks the quote as a renewal of $initialOrder's subscription and saves it. Set the quote's payment first.
     *
     * @throws LocalizedException When the renewal can't continue $initialOrder's recurring payments
     */
    public function markAsRenewal(Quote $quote, OrderInterface $initialOrder): void
    {
        $payment = $initialOrder->getPayment();
        // cc_trans_id keeps the PublicSquare payment ID; a refund can move last_trans_id to the refund's ID.
        $paymentId = (string) $payment?->getCcTransId();
        // Unlike raw_details_info, cardId isn't replaced by later API responses.
        $cardId = $payment?->getAdditionalInformation('cardId');
        if (!str_starts_with($paymentId, 'pmt_') || !$cardId) {
            throw new LocalizedException(
                __(
                    'Order %1 was not paid through PublicSquare, so it can\'t start a recurring payment.',
                    $initialOrder->getIncrementId()
                )
            );
        }
        $renewalCardId = $this->getRenewalCardId($quote);
        if (!$renewalCardId) {
            throw new LocalizedException(
                __('Set the quote\'s PublicSquare payment before marking it as a subscription renewal.')
            );
        }
        if ($renewalCardId !== $cardId) {
            throw new LocalizedException(
                __(
                    'The renewal charges another card than order %1, so it can\'t continue its recurring payment.',
                    $initialOrder->getIncrementId()
                )
            );
        }
        $quote->getPayment()->setAdditionalInformation(
            self::INITIAL_PAYMENT_KEY,
            ['paymentId' => $paymentId, 'cardId' => $cardId]
        );
        $this->quoteRepository->save($quote);
    }

    /** The payment this renews, or null if it isn't a renewal or now charges a different card. */
    public function getInitialPaymentId(InfoInterface $payment, string $cardId): ?string
    {
        $initialPayment = $payment->getAdditionalInformation(self::INITIAL_PAYMENT_KEY);
        if (!$initialPayment) {
            return null;
        }
        if ($initialPayment['cardId'] !== $cardId) {
            $this->logger->warning('PSQ renewal uses another card than its initial payment; sent as one-off', [
                'initialPaymentId' => $initialPayment['paymentId'],
            ]);
            return null;
        }
        return $initialPayment['paymentId'];
    }

    // Finds the card the same way CaptureCommand does.
    private function getRenewalCardId(Quote $quote): ?string
    {
        $payment = $quote->getPayment();
        if ($nonce = $payment->getAdditionalInformation('payment_method_nonce')) {
            return $nonce;
        }
        if ($publicHash = $payment->getAdditionalInformation('public_hash')) {
            $token = $this->tokenManagement->getByPublicHash($publicHash, $quote->getCustomerId());
            return $token ? (string) $token->getGatewayToken() : null;
        }
        return $payment->getAdditionalInformation('cardId') ?: null;
    }
}
