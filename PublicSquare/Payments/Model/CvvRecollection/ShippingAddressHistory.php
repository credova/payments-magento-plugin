<?php

namespace PublicSquare\Payments\Model\CvvRecollection;

use Magento\Framework\App\ResourceConnection;
use PublicSquare\Payments\Helper\Config;

/**
 * Finds the addresses a saved card has shipped to on a customer's earlier orders.
 *
 * Every PublicSquare order payment stores the PSQ card ID in additional_information.cardId, whether it
 * was paid with a new card, a saved card, or from the admin. That ID equals the saved card's
 * vault gateway_token, so it links orders to saved cards. Magento's vault link table is not used: it
 * has no row for the order that first saved a card.
 */
class ShippingAddressHistory
{
    private ResourceConnection $resourceConnection;

    public function __construct(ResourceConnection $resourceConnection)
    {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * @return ShippingAddress[] Shipping addresses of the customer's orders paid with this card, in any order state
     */
    public function getShippingAddressesForCard(int $customerId, string $cardId): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->resourceConnection->getTableName('sales_order')], [])
            ->join(
                ['p' => $this->resourceConnection->getTableName('sales_order_payment')],
                'p.parent_id = o.entity_id',
                ['additional_information']
            )
            ->join(
                ['a' => $this->resourceConnection->getTableName('sales_order_address')],
                "a.parent_id = o.entity_id AND a.address_type = 'shipping'",
                ['street', 'city', 'region_id', 'region', 'postcode', 'country_id']
            )
            ->where('o.customer_id = ?', $customerId)
            ->where('p.method = ?', Config::CODE)
            // A PSQ payment was actually created for the order.
            ->where('p.last_trans_id LIKE ?', 'pmt\_%');

        $addresses = [];
        foreach ($connection->fetchAll($select) as $row) {
            // Decoded here, not with JSON_EXTRACT in SQL: one invalid row would fail the whole query.
            $info = json_decode((string)$row['additional_information'], true);
            if (!is_array($info) || ($info['cardId'] ?? null) !== $cardId) {
                continue;
            }
            $addresses[] = ShippingAddress::fromOrderAddressRow($row);
        }
        return $addresses;
    }
}
