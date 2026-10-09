<?php

namespace PublicSquare\Payments\Model\CvvRecollection;

use Magento\Quote\Api\Data\AddressInterface as QuoteAddressInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;

/**
 * A shipping address reduced to the parts PublicSquare's card security code rule compares: street line 1, postal
 * code and country. Matching the rule exactly means checkout asks for the CVV when, and only when, PublicSquare
 * would otherwise reject the payment.
 */
class ShippingAddress
{
    private ?string $line1;
    private ?string $postcode;
    private ?string $countryId;

    public function __construct(?string $line1, ?string $postcode, ?string $countryId)
    {
        $this->line1 = $line1;
        $this->postcode = $postcode;
        $this->countryId = $countryId;
    }

    public static function fromAddress(QuoteAddressInterface|OrderAddressInterface $address): self
    {
        $street = $address->getStreet();
        $street = is_array($street) ? $street : explode("\n", (string)$street);
        return new self($street[0] ?? null, $address->getPostcode(), $address->getCountryId());
    }

    /**
     * Builds an address from a sales_order_address row, where street lines are joined with newlines.
     *
     * @param array<string, mixed> $row
     */
    public static function fromOrderAddressRow(array $row): self
    {
        return new self(
            explode("\n", (string)($row['street'] ?? ''))[0],
            $row['postcode'] ?? null,
            $row['country_id'] ?? null,
        );
    }

    /**
     * A normalized key: equal fingerprints mean the addresses match. Ignores case and extra spaces, and compares
     * US postcodes by their first five characters (so ZIP+4 matches the plain ZIP), as PublicSquare does.
     */
    public function getFingerprint(): string
    {
        $countryId = $this->normalize($this->countryId);
        $postcode = $this->normalize($this->postcode);
        if ($countryId === 'us') {
            $postcode = substr($postcode, 0, 5);
        }
        return implode('|', [$this->normalize($this->line1), $postcode, $countryId]);
    }

    public function isSameAddressAs(ShippingAddress $other): bool
    {
        return $this->getFingerprint() === $other->getFingerprint();
    }

    private function normalize(?string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', strtolower((string)$value)));
    }
}
