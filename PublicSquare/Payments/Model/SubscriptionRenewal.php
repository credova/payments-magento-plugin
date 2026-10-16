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
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderInterface;
use PublicSquare\Payments\Logger\Logger;

/**
 * Marks a cart as a subscription renewal, so its payment goes to PublicSquare as a recurring charge of the
 * subscription's first payment. A merchant's subscription module calls markAsRenewal() before it places the
 * renewal order, in the same PHP process.
 *
 * The mark lives only in this shared instance. Shoppers can't set it: nothing in checkout or the web API calls it.
 * It stays for the life of the process, so a retry of the same cart is still a renewal.
 */
class SubscriptionRenewal
{
    /**
     * @var array[] The subscription's first PublicSquare payment, as ['paymentId' => ..., 'cardId' => ...], by quote ID
     */
    private $initialPayments = [];

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @param Logger $logger
     */
    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Marks the quote's payment as a renewal of the subscription that $initialOrder started.
     *
     * @param CartInterface $quote The renewal order's quote, already saved
     * @param OrderInterface $initialOrder The order that started the subscription, paid through PublicSquare
     * @return void
     * @throws LocalizedException When the quote isn't saved, or $initialOrder has no PublicSquare payment
     */
    public function markAsRenewal(CartInterface $quote, OrderInterface $initialOrder): void
    {
        if (!$quote->getId()) {
            throw new LocalizedException(__('Save the quote before marking it as a subscription renewal.'));
        }
        $payment = $initialOrder->getPayment();
        // cc_trans_id keeps the PublicSquare payment ID; a refund can move last_trans_id to the refund's ID.
        $paymentId = (string) $payment?->getCcTransId();
        $cardId = $payment?->getAdditionalInformation('raw_details_info')['payment_method']['card']['id'] ?? null;
        if (!str_starts_with($paymentId, 'pmt_') || !$cardId) {
            throw new LocalizedException(
                __(
                    'Order %1 was not paid through PublicSquare, so it can\'t start a recurring payment.',
                    $initialOrder->getIncrementId()
                )
            );
        }
        $this->initialPayments[(int) $quote->getId()] = ['paymentId' => $paymentId, 'cardId' => $cardId];
    }

    /**
     * The PublicSquare payment ID the quote's payment renews, or null when the quote isn't a renewal.
     *
     * Also null when the renewal charges another card: a recurring charge must use the card of the payment it
     * continues.
     *
     * @param int $quoteId
     * @param string $cardId The PublicSquare card the renewal charges
     * @return string|null
     */
    public function getInitialPaymentId(int $quoteId, string $cardId): ?string
    {
        $initialPayment = $this->initialPayments[$quoteId] ?? null;
        if (!$initialPayment) {
            return null;
        }
        if ($initialPayment['cardId'] !== $cardId) {
            $this->logger->warning('PSQ renewal uses another card than its initial payment; sent as one-off', [
                'quoteId' => $quoteId,
                'initialPaymentId' => $initialPayment['paymentId'],
            ]);
            return null;
        }
        return $initialPayment['paymentId'];
    }
}
