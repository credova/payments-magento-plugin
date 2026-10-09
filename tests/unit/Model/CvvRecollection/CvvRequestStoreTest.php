<?php

namespace PublicSquare\Payments\Test\Unit\Model\CvvRecollection;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Model\CvvRecollection\CvvRequestStore;

class CvvRequestStoreTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private CvvRequestStore $store;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->store = new CvvRequestStore($resource);
    }

    public function testStampInsertsInUtcAndKeepsAnExistingStamp(): void
    {
        $this->connection->expects($this->once())
            ->method('insertArray')
            ->with(
                'publicsquare_cvv_request',
                ['quote_id', 'payment_token_id', 'requested_at'],
                [[42, 3, '2026-10-08 22:42:10']],
                AdapterInterface::INSERT_IGNORE
            );

        $this->store->stamp(42, 3, new \DateTimeImmutable('2026-10-08 17:42:10', new \DateTimeZone('America/Chicago')));
    }

    public function testGetRequestedAtReadsTheStampAsUtc(): void
    {
        $this->givenSelect();
        $this->connection->method('fetchOne')->willReturn('2026-10-08 22:42:10');

        $requestedAt = $this->store->getRequestedAt(42, 3);

        $this->assertSame('2026-10-08T22:42:10+00:00', $requestedAt->format(DATE_ATOM));
    }

    public function testGetRequestedAtIsNullWithoutAStamp(): void
    {
        $this->givenSelect();
        $this->connection->method('fetchOne')->willReturn(false);

        $this->assertNull($this->store->getRequestedAt(42, 3));
    }

    public function testClearForQuoteDeletesTheCartsRows(): void
    {
        $this->connection->expects($this->once())
            ->method('delete')
            ->with('publicsquare_cvv_request', ['quote_id = ?' => 42]);

        $this->store->clearForQuote(42);
    }

    private function givenSelect(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
    }
}
