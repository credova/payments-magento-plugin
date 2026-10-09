<?php

namespace PublicSquare\Payments\Model\CvvRecollection;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Records when checkout first asked for a saved card's CVV (the "stamp"), per cart and card.
 *
 * Written only by the server, so the browser can't set or move it. At payment time the guard uses
 * it to check the CVV step ran, and passes it to PublicSquare as require_fresh_cvc.updated_after.
 * Times are UTC.
 */
class CvvRequestStore
{
    public const TABLE = 'publicsquare_cvv_request';

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Records that the CVV was requested now, unless it already was for this cart and card.
     *
     * Keeping the first time means a later re-check (an address change, a page reload) can't move the
     * stamp past a CVV the customer already re-entered.
     */
    public function stamp(int $quoteId, int $paymentTokenId, ?\DateTimeImmutable $now = null): void
    {
        $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->resourceConnection->getConnection()->insertArray(
            $this->resourceConnection->getTableName(self::TABLE),
            ['quote_id', 'payment_token_id', 'requested_at'],
            [[$quoteId, $paymentTokenId, $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')]],
            AdapterInterface::INSERT_IGNORE
        );
    }

    /**
     * When the CVV was first requested for this cart and card, or null if it never was.
     */
    public function getRequestedAt(int $quoteId, int $paymentTokenId): ?\DateTimeImmutable
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE), ['requested_at'])
            ->where('quote_id = ?', $quoteId)
            ->where('payment_token_id = ?', $paymentTokenId);
        $value = $connection->fetchOne($select);

        return $value ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }

    public function clearForQuote(int $quoteId): void
    {
        $this->resourceConnection->getConnection()->delete(
            $this->resourceConnection->getTableName(self::TABLE),
            ['quote_id = ?' => $quoteId]
        );
    }
}
