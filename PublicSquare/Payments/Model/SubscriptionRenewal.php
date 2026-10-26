<?php

namespace PublicSquare\Payments\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use PublicSquare\Payments\Helper\Config;
use PublicSquare\Payments\Logger\Logger;

/**
 * Marks a cart as a subscription renewal, so PublicSquare charges it as a recurring payment of the first order.
 * QuotePaymentPlugin stops storefront and web API clients from setting the mark themselves.
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
     * @var CardResolver
     */
    private $cardResolver;

    /**
     * @var Logger
     */
    private $logger;

    public function __construct(
        CartRepositoryInterface $quoteRepository,
        CardResolver $cardResolver,
        Logger $logger
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->cardResolver = $cardResolver;
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
                    'This renewal can\'t be linked to order %1, which wasn\'t paid through PublicSquare.',
                    $initialOrder->getIncrementId()
                )
            );
        }
        $renewalCardId = $this->getRenewalCardId($quote);
        if (!$renewalCardId) {
            throw new LocalizedException(
                __(
                    'This renewal can\'t be linked to order %1, because its payment isn\'t an active PublicSquare card.',
                    $initialOrder->getIncrementId()
                )
            );
        }
        if ($renewalCardId !== $cardId) {
            throw new LocalizedException(
                __(
                    'This renewal can\'t be linked to order %1, which was paid with a different card.',
                    $initialOrder->getIncrementId()
                )
            );
        }
        $quotePayment = $quote->getPayment();
        $quotePayment->setAdditionalInformation(
            self::INITIAL_PAYMENT_KEY,
            ['paymentId' => $paymentId, 'cardId' => $cardId]
        );
        // One key per marked attempt: a redelivered queue message reuses it, so PublicSquare doesn't charge twice.
        $quotePayment->setAdditionalInformation('idempotencyKey', bin2hex(random_bytes(16)));
        $this->quoteRepository->save($quote);
    }

    /** The payment this renews, or null if it isn't a renewal or now charges a different card. */
    public function getInitialPaymentId(InfoInterface $payment, string $cardId): ?string
    {
        $initialPayment = $payment->getAdditionalInformation(self::INITIAL_PAYMENT_KEY);
        if (!is_array($initialPayment) || empty($initialPayment['paymentId'])) {
            return null;
        }
        if (($initialPayment['cardId'] ?? null) !== $cardId) {
            $this->logger->warning('PSQ renewal charged as a one-off: its card differs from the initial payment', [
                'initialPaymentId' => $initialPayment['paymentId'],
            ]);
            return null;
        }
        return $initialPayment['paymentId'];
    }

    private function getRenewalCardId(Quote $quote): ?string
    {
        $payment = $quote->getPayment();
        if (!in_array($payment->getMethod(), [Config::CODE, Config::VAULT_CODE], true)) {
            return null;
        }
        return $this->cardResolver->getCardId($payment, $quote->getCustomerId());
    }
}
