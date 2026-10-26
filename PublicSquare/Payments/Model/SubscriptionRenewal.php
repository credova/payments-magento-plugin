<?php

namespace PublicSquare\Payments\Model;

use Magento\Framework\App\ResourceConnection;
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

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    public function __construct(
        CartRepositoryInterface $quoteRepository,
        CardResolver $cardResolver,
        Logger $logger,
        ResourceConnection $resourceConnection
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->cardResolver = $cardResolver;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
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
        $quotePayment->setAdditionalInformation('idempotencyKey', $this->newIdempotencyKey());
        $this->quoteRepository->save($quote);
    }

    /**
     * Gives a marked quote a new idempotency key after its payment was cancelled or refunded, so placing it again
     * charges the card instead of getting the undone payment back from PublicSquare.
     */
    public function startNewAttempt(Quote $quote): void
    {
        $payment = $quote->getPayment();
        if (!is_array($payment->getAdditionalInformation(self::INITIAL_PAYMENT_KEY))) {
            return;
        }
        $payment->setAdditionalInformation('idempotencyKey', $this->newIdempotencyKey());
        $this->quoteRepository->save($quote);
    }

    /**
     * The increment ID of the order already placed for this marked attempt, or null. PublicSquare returns the
     * attempt's first payment instead of charging again, so placing it again would make a second order for it.
     *
     * @param int|string|null $customerId
     */
    public function getPlacedOrderIncrementId(InfoInterface $payment, $customerId): ?string
    {
        $key = (string) $payment->getAdditionalInformation('idempotencyKey');
        if ($key === '' || !$customerId) {
            return null;
        }
        $keyJson = substr((string) json_encode(['idempotencyKey' => $key]), 1, -1);
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->resourceConnection->getTableName('sales_order')], ['increment_id'])
            ->join(
                ['p' => $this->resourceConnection->getTableName('sales_order_payment')],
                'p.parent_id = o.entity_id',
                ['additional_information']
            )
            ->where('o.customer_id = ?', (int) $customerId)
            ->where('p.additional_information LIKE ?', '%' . addcslashes($keyJson, '\\%_') . '%');
        foreach ($connection->fetchAll($select) as $row) {
            $info = json_decode((string) $row['additional_information'], true);
            if (is_array($info) && ($info['idempotencyKey'] ?? null) === $key
                && is_array($info[self::INITIAL_PAYMENT_KEY] ?? null)
            ) {
                return (string) $row['increment_id'];
            }
        }
        return null;
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

    private function newIdempotencyKey(): string
    {
        return bin2hex(random_bytes(16));
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
