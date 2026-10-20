<?php
/**
 * SubscriptionRenewal
 *
 * @category  PublicSquare
 * @package   PublicSquare_Payments
 * @author    PublicSquare <info@publicsquare.com>
 * @copyright 2024 PublicSquare
 * @license   http://opensource.org/licenses/osl-3.0.php (OSL 3.0)
 * @link      https://publicsquare.com/
 */

namespace PublicSquare\Payments\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PublicSquare\Payments\Logger\Logger;

/**
 * Marks a cart as a subscription renewal, so its payment goes to PublicSquare as a recurring charge of the
 * subscription's first payment. A merchant's subscription module calls markAsRenewal() before it places the
 * renewal order.
 *
 * The mark is saved in the quote payment's additional information, which Magento copies to the order payment, so the
 * order can be placed in another process. Shoppers can't set it: QuotePaymentPlugin drops the key from the
 * additional_data a client sends.
 */
class SubscriptionRenewal
{
    /**
     * Additional-information key of the mark: the initial payment, as ['paymentId' => ..., 'cardId' => ...]
     */
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

    /**
     * @param CartRepositoryInterface $quoteRepository
     * @param PaymentTokenManagementInterface $tokenManagement
     * @param Logger $logger
     */
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
     * Marks the quote's payment as a renewal of the subscription that $initialOrder started, and saves the quote.
     *
     * Set the quote's payment first: the renewal must charge the card of the initial order's payment.
     *
     * @param Quote $quote The renewal order's quote, with its PublicSquare payment set
     * @param OrderInterface $initialOrder The order that started the subscription, paid through PublicSquare
     * @return void
     * @throws LocalizedException When $initialOrder has no PublicSquare payment, the quote's payment isn't set, or
     *     it charges another card than $initialOrder
     */
    public function markAsRenewal(Quote $quote, OrderInterface $initialOrder): void
    {
        $payment = $initialOrder->getPayment();
        // cc_trans_id keeps the PublicSquare payment ID; a refund can move last_trans_id to the refund's ID.
        $paymentId = (string) $payment?->getCcTransId();
        // The card the payment was created with. Unlike raw_details_info, later API responses don't replace it.
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

    /**
     * Whether markAsRenewal() marked the payment's quote, whichever card the payment charges.
     *
     * @param InfoInterface $payment The order payment
     * @return bool
     */
    public function isRenewal(InfoInterface $payment): bool
    {
        return (bool) $payment->getAdditionalInformation(self::INITIAL_PAYMENT_KEY);
    }

    /**
     * The PublicSquare payment ID the payment renews, or null when it isn't a renewal.
     *
     * Also null when the renewal charges another card, such as when the card changed after markAsRenewal(): a
     * recurring charge must use the card of the payment it continues.
     *
     * @param InfoInterface $payment The order payment
     * @param string $cardId The PublicSquare card the renewal charges
     * @return string|null
     */
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

    /**
     * The PublicSquare card the quote's payment charges, found the way CaptureCommand finds it.
     *
     * @param Quote $quote
     * @return string|null
     */
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
