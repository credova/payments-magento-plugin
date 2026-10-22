<?php

namespace PublicSquare\Payments\Model\CvvRecollection;

use Magento\Framework\App\ResourceConnection;
use PublicSquare\Payments\Helper\Config;

/**
 * Reads a saved card's successful payments on a customer's earlier orders, the way PublicSquare's card security code
 * rule does: payments whose status is succeeded, requires capture or partially captured, and the shipping addresses
 * of all of them.
 *
 * Every PublicSquare order payment stores the PSQ card ID in additional_information.cardId, whether it was paid with
 * a new card, a saved card, or from the admin. That ID equals the saved card's vault gateway_token, so it links
 * orders to saved cards. The payment's status is the PSQ status in additional_information.raw_details_info, which
 * capture and cancel keep up to date.
 */
class ShippingAddressHistory
{
    private const SUCCESSFUL_STATUSES = ['succeeded', 'requires_capture', 'partially_captured', 'partially-captured'];

    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    public function getCardPaymentHistory(int $customerId, string $cardId): CardPaymentHistory
    {
        $connection = $this->resourceConnection->getConnection();
        $cardIdPattern = '%' . self::escapeLike((string)json_encode(['cardId' => $cardId])) . '%';
        $select = $connection->select()
            ->from(['o' => $this->resourceConnection->getTableName('sales_order')], [])
            ->join(
                ['p' => $this->resourceConnection->getTableName('sales_order_payment')],
                'p.parent_id = o.entity_id',
                ['additional_information']
            )
            // A virtual order has no shipping address, but its payment still counts.
            ->joinLeft(
                ['a' => $this->resourceConnection->getTableName('sales_order_address')],
                "a.parent_id = o.entity_id AND a.address_type = 'shipping'",
                ['street', 'postcode', 'country_id']
            )
            ->where('o.customer_id = ?', $customerId)
            ->where('p.method = ?', Config::CODE)
            // A PSQ payment was actually created for the order.
            ->where('p.last_trans_id LIKE ?', 'pmt\_%')
            // Narrows the rows to this card. The decode below is still the exact check.
            ->where('p.additional_information LIKE ?', $cardIdPattern)
            ->order('o.entity_id DESC');

        $successfulPayments = 0;
        $addresses = [];
        foreach ($connection->fetchAll($select) as $row) {
            // Decoded here, not with JSON_EXTRACT in SQL: one invalid row would fail the whole query.
            $info = json_decode((string)$row['additional_information'], true);
            if (!is_array($info) || ($info['cardId'] ?? null) !== $cardId) {
                continue;
            }
            if (!in_array($info['raw_details_info']['status'] ?? null, self::SUCCESSFUL_STATUSES, true)) {
                continue;
            }
            $successfulPayments++;
            if ($row['street'] !== null) {
                $addresses[] = ShippingAddress::fromOrderAddressRow($row);
            }
        }
        return new CardPaymentHistory($successfulPayments, $addresses);
    }

    /**
     * The JSON `{"cardId":"..."}` without its braces, with LIKE's wildcards escaped.
     */
    private static function escapeLike(string $json): string
    {
        return addcslashes(substr($json, 1, -1), '\\%_');
    }
}
