<?php

namespace PublicSquare\Payments\Test\Unit\Model\CvvRecollection;

use Magento\Quote\Api\Data\AddressInterface;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddress;

/**
 * Addresses match the way PublicSquare's card security code rule matches them: street line 1, postal code and
 * country, ignoring case and extra spaces, with US ZIP codes compared by their first five digits.
 */
class ShippingAddressTest extends TestCase
{
    public function testSameAddressWithDifferentCaseAndSpacingMatches(): void
    {
        $a = new ShippingAddress('123 Main St', '19711', 'US');
        $b = new ShippingAddress('  123  MAIN st ', '19711-1234', 'us');

        $this->assertTrue($a->isSameAddressAs($b));
    }

    public function testDifferentLineOneDoesNotMatch(): void
    {
        $this->assertFalse(
            (new ShippingAddress('123 Main St', '19711', 'US'))->isSameAddressAs(new ShippingAddress('125 Main St', '19711', 'US'))
        );
    }

    public function testPunctuationCounts(): void
    {
        // PublicSquare ignores case and spaces only, so "St." and "St" are different addresses there too.
        $this->assertFalse(
            (new ShippingAddress('123 Main St.', '19711', 'US'))->isSameAddressAs(new ShippingAddress('123 Main St', '19711', 'US'))
        );
    }

    public function testDifferentPostcodeOrCountryDoesNotMatch(): void
    {
        $a = new ShippingAddress('1 High St', '19711', 'US');

        $this->assertFalse($a->isSameAddressAs(new ShippingAddress('1 High St', '19712', 'US')));
        $this->assertFalse($a->isSameAddressAs(new ShippingAddress('1 High St', '19711', 'CA')));
    }

    public function testPostcodesOutsideTheUsAreNotTruncated(): void
    {
        $this->assertFalse(
            (new ShippingAddress('1 Rue Test', '7500123', 'FR'))->isSameAddressAs(new ShippingAddress('1 Rue Test', '7500199', 'FR'))
        );
    }

    public function testLineTwoCityAndRegionDoNotCount(): void
    {
        $a = $this->quoteAddress(['123 Main St', 'Apt 4'], 'Newark', 'Delaware');
        $b = $this->quoteAddress(['123 Main St', 'Apt 5'], 'Wilmington', 'DE');

        $this->assertTrue(ShippingAddress::fromAddress($a)->isSameAddressAs(ShippingAddress::fromAddress($b)));
    }

    public function testFromAddressAndFromOrderAddressRowAgree(): void
    {
        $fromRow = ShippingAddress::fromOrderAddressRow([
            'street' => "123 Main St\nApt 4",
            'postcode' => '19711',
            'country_id' => 'US',
        ]);

        $this->assertTrue(
            ShippingAddress::fromAddress($this->quoteAddress(['123 Main St', 'Apt 4'], 'Newark', 'Delaware'))
                ->isSameAddressAs($fromRow)
        );
    }

    private function quoteAddress(array $street, string $city, string $region): AddressInterface
    {
        $address = $this->createMock(AddressInterface::class);
        $address->method('getStreet')->willReturn($street);
        $address->method('getCity')->willReturn($city);
        $address->method('getRegion')->willReturn($region);
        $address->method('getPostcode')->willReturn('19711');
        $address->method('getCountryId')->willReturn('US');
        return $address;
    }
}
