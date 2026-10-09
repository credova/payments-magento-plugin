<?php

namespace PublicSquare\Payments\Test\Unit\Model\CvvRecollection;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddress;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddressHistory;

class ShippingAddressHistoryTest extends TestCase
{
    private const CARD_ID = 'card_saved123';

    public function testReturnsOnlyAddressesOfOrdersPaidWithTheCard(): void
    {
        $history = $this->historyWithRows([
            $this->row(['cardId' => self::CARD_ID], '123 Main St'),
            $this->row(['cardId' => 'card_other'], '9 Other Rd'),
            $this->row(['cardId' => self::CARD_ID, 'idempotencyKey' => 'x'], '456 Oak Ave'),
        ]);

        $addresses = $history->getShippingAddressesForCard(7, self::CARD_ID);

        $this->assertCount(2, $addresses);
        $this->assertTrue($addresses[0]->isSamePlaceAs($this->address('123 Main St')));
        $this->assertTrue($addresses[1]->isSamePlaceAs($this->address('456 Oak Ave')));
    }

    public function testSkipsRowsWithUnreadablePaymentInformation(): void
    {
        $history = $this->historyWithRows([
            ['additional_information' => '{not json'] + $this->row([], '1 Bad St'),
            ['additional_information' => null] + $this->row([], '2 Bad St'),
            $this->row(['cardId' => self::CARD_ID], '123 Main St'),
        ]);

        $this->assertCount(1, $history->getShippingAddressesForCard(7, self::CARD_ID));
    }

    public function testFiltersTheQueryToTheCustomersPublicSquarePayments(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $wheres = [];
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select, &$wheres) {
            $wheres[$cond] = $value;
            return $select;
        });

        $this->historyWithRows([], $select)->getShippingAddressesForCard(7, self::CARD_ID);

        $this->assertSame(
            [
                'o.customer_id = ?' => 7,
                'p.method = ?' => 'publicsquare_payments',
                'p.last_trans_id LIKE ?' => 'pmt\_%',
            ],
            $wheres
        );
    }

    private function historyWithRows(array $rows, ?Select $select = null): ShippingAddressHistory
    {
        if ($select === null) {
            $select = $this->createMock(Select::class);
            $select->method('from')->willReturnSelf();
            $select->method('join')->willReturnSelf();
            $select->method('where')->willReturnSelf();
        }
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new ShippingAddressHistory($resource);
    }

    private function row(array $info, string $street): array
    {
        return [
            'additional_information' => json_encode($info),
            'street' => $street,
            'city' => 'Newark',
            'region_id' => '15',
            'region' => 'Delaware',
            'postcode' => '19711',
            'country_id' => 'US',
        ];
    }

    private function address(string $street): ShippingAddress
    {
        return new ShippingAddress([$street], 'Newark', 15, 'Delaware', '19711', 'US');
    }
}
