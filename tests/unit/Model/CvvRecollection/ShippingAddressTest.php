<?php

namespace PublicSquare\Payments\Test\Unit\Model\CvvRecollection;

use Magento\Quote\Api\Data\AddressInterface;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddress;

class ShippingAddressTest extends TestCase
{
    public function testSameAddressWithDifferentFormattingIsTheSamePlace(): void
    {
        $a = new ShippingAddress(['123 Main St.'], 'Newark', 15, 'Delaware', '19711', 'US');
        $b = new ShippingAddress(['  123  MAIN st '], 'NEWARK ', 15, 'DE', '19711-1234', 'us');

        $this->assertTrue($a->isSamePlaceAs($b));
    }

    public function testDifferentStreetIsADifferentPlace(): void
    {
        $a = new ShippingAddress(['123 Main St'], 'Newark', 15, null, '19711', 'US');
        $b = new ShippingAddress(['125 Main St'], 'Newark', 15, null, '19711', 'US');

        $this->assertFalse($a->isSamePlaceAs($b));
    }

    public function testDifferentSecondStreetLineIsADifferentPlace(): void
    {
        $a = new ShippingAddress(['123 Main St', 'Apt 4'], 'Newark', 15, null, '19711', 'US');
        $b = new ShippingAddress(['123 Main St', 'Apt 5'], 'Newark', 15, null, '19711', 'US');

        $this->assertFalse($a->isSamePlaceAs($b));
    }

    public function testRegionTextIsComparedWhenThereIsNoRegionId(): void
    {
        $a = new ShippingAddress(['1 High St'], 'London', null, 'Greater London', 'SW1A 1AA', 'GB');
        $b = new ShippingAddress(['1 High St'], 'London', null, 'greater london', 'sw1a1aa', 'GB');
        $c = new ShippingAddress(['1 High St'], 'London', null, 'Kent', 'SW1A 1AA', 'GB');

        $this->assertTrue($a->isSamePlaceAs($b));
        $this->assertFalse($a->isSamePlaceAs($c));
    }

    public function testPostcodesOutsideTheUsAreNotTruncated(): void
    {
        $a = new ShippingAddress(['1 Rue Test'], 'Paris', null, null, '7500123', 'FR');
        $b = new ShippingAddress(['1 Rue Test'], 'Paris', null, null, '7500199', 'FR');

        $this->assertFalse($a->isSamePlaceAs($b));
    }

    public function testFromAddressReadsAQuoteAddress(): void
    {
        $quoteAddress = $this->createMock(AddressInterface::class);
        $quoteAddress->method('getStreet')->willReturn(['123 Main St', '']);
        $quoteAddress->method('getCity')->willReturn('Newark');
        $quoteAddress->method('getRegionId')->willReturn('15');
        $quoteAddress->method('getRegion')->willReturn('Delaware');
        $quoteAddress->method('getPostcode')->willReturn('19711');
        $quoteAddress->method('getCountryId')->willReturn('US');

        $fromRow = ShippingAddress::fromOrderAddressRow([
            'street' => "123 Main St\n",
            'city' => 'Newark',
            'region_id' => '15',
            'region' => 'Delaware',
            'postcode' => '19711',
            'country_id' => 'US',
        ]);

        $this->assertTrue(ShippingAddress::fromAddress($quoteAddress)->isSamePlaceAs($fromRow));
    }
}
