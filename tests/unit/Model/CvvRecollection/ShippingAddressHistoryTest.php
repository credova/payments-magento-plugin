<?php

namespace PublicSquare\Payments\Test\Unit\Model\CvvRecollection;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddress;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddressHistory;

/**
 * Counts a card's payments the way PublicSquare's rule does: successful ones only (succeeded, requires capture,
 * partially captured), and the shipping addresses of all of them.
 */
class ShippingAddressHistoryTest extends TestCase
{
    private const CARD_ID = 'card_saved123';

    public function testCountsOnlySuccessfulPaymentsWithThisCard(): void
    {
        $history = $this->historyWithRows([
            $this->row(self::CARD_ID, 'succeeded', '1 First St'),
            $this->row('card_other', 'succeeded', '9 Other Rd'),
            $this->row(self::CARD_ID, 'requires_capture', '2 Second St'),
            $this->row(self::CARD_ID, 'cancelled', '3 Voided St'),
            $this->row(self::CARD_ID, 'partially_captured', '4 Fourth St'),
        ])->getCardPaymentHistory(7, self::CARD_ID);

        $this->assertSame(3, $history->successfulPayments);
        $this->assertSame(
            ['1 first st|19711|us', '2 second st|19711|us', '4 fourth st|19711|us'],
            array_map(fn (ShippingAddress $a) => $a->getFingerprint(), $history->shippingAddresses)
        );
    }

    public function testCountsAVirtualOrderButHasNoAddressForIt(): void
    {
        $history = $this->historyWithRows([
            ['street' => null, 'postcode' => null, 'country_id' => null] + $this->row(self::CARD_ID, 'succeeded', ''),
        ])->getCardPaymentHistory(7, self::CARD_ID);

        $this->assertSame(1, $history->successfulPayments);
        $this->assertSame([], $history->shippingAddresses);
    }

    public function testKeepsTheAddressOfEverySuccessfulPayment(): void
    {
        $rows = [];
        for ($i = 1; $i <= 150; $i++) {
            $rows[] = $this->row(self::CARD_ID, 'succeeded', "$i Main St");
        }

        $history = $this->historyWithRows($rows)->getCardPaymentHistory(7, self::CARD_ID);

        $this->assertSame(150, $history->successfulPayments);
        $this->assertCount(150, $history->shippingAddresses);
        $this->assertSame('150 main st|19711|us', $history->shippingAddresses[149]->getFingerprint());
    }

    public function testSkipsRowsWithUnreadablePaymentInformation(): void
    {
        $history = $this->historyWithRows([
            ['additional_information' => '{not json'] + $this->row(self::CARD_ID, 'succeeded', '1 Bad St'),
            ['additional_information' => null] + $this->row(self::CARD_ID, 'succeeded', '2 Bad St'),
            $this->row(self::CARD_ID, 'succeeded', '123 Main St'),
        ])->getCardPaymentHistory(7, self::CARD_ID);

        $this->assertSame(1, $history->successfulPayments);
    }

    public function testFiltersTheQueryToTheCustomersPublicSquarePaymentsWithThisCard(): void
    {
        $select = $this->select();
        $wheres = [];
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select, &$wheres) {
            $wheres[$cond] = $value;
            return $select;
        });
        $select->expects($this->once())->method('order')->with('o.entity_id DESC')->willReturnSelf();

        $this->historyWithRows([], $select)->getCardPaymentHistory(7, self::CARD_ID);

        $this->assertSame(
            [
                'o.customer_id = ?' => 7,
                'p.method = ?' => 'publicsquare_payments',
                'p.last_trans_id LIKE ?' => 'pmt\_%',
                'p.additional_information LIKE ?' => '%"cardId":"card\_saved123"%',
            ],
            $wheres
        );
    }

    private function select(): Select
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('joinLeft')->willReturnSelf();
        return $select;
    }

    private function historyWithRows(array $rows, ?Select $select = null): ShippingAddressHistory
    {
        if ($select === null) {
            $select = $this->select();
            $select->method('where')->willReturnSelf();
            $select->method('order')->willReturnSelf();
        }
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new ShippingAddressHistory($resource);
    }

    private function row(string $cardId, string $status, string $street): array
    {
        return [
            'additional_information' => json_encode(['cardId' => $cardId, 'raw_details_info' => ['status' => $status]]),
            'street' => $street,
            'postcode' => '19711',
            'country_id' => 'US',
        ];
    }
}
