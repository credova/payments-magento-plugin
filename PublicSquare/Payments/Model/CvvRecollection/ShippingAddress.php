<?php

namespace PublicSquare\Payments\Model\CvvRecollection;

use Magento\Quote\Api\Data\AddressInterface as QuoteAddressInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;

/**
 * A shipping address reduced to the parts that decide whether two addresses are the same place.
 *
 * Name, phone and company are left out: a saved card shipping to the same address for a different
 * recipient is not a new address.
 */
class ShippingAddress
{
    /** @var string[] */
    private array $street;
    private ?string $city;
    private ?int $regionId;
    private ?string $region;
    private ?string $postcode;
    private ?string $countryId;

    /**
     * @param string[] $street
     */
    public function __construct(
        array $street,
        ?string $city,
        ?int $regionId,
        ?string $region,
        ?string $postcode,
        ?string $countryId,
    ) {
        $this->street = $street;
        $this->city = $city;
        $this->regionId = $regionId;
        $this->region = $region;
        $this->postcode = $postcode;
        $this->countryId = $countryId;
    }

    public static function fromAddress(QuoteAddressInterface|OrderAddressInterface $address): self
    {
        $street = $address->getStreet();
        return new self(
            is_array($street) ? $street : explode("\n", (string)$street),
            $address->getCity(),
            $address->getRegionId() ? (int)$address->getRegionId() : null,
            $address->getRegion(),
            $address->getPostcode(),
            $address->getCountryId(),
        );
    }

    /**
     * Builds an address from a sales_order_address row, where street lines are joined with newlines.
     *
     * @param array<string, mixed> $row
     */
    public static function fromOrderAddressRow(array $row): self
    {
        return new self(
            explode("\n", (string)($row['street'] ?? '')),
            $row['city'] ?? null,
            !empty($row['region_id']) ? (int)$row['region_id'] : null,
            $row['region'] ?? null,
            $row['postcode'] ?? null,
            $row['country_id'] ?? null,
        );
    }

    /**
     * A normalized key: equal fingerprints mean the same place.
     *
     * Ignores case, punctuation and extra spaces, uses the region ID when there is one, and compares
     * US postcodes by their first five digits (so ZIP+4 matches the plain ZIP).
     */
    public function getFingerprint(): string
    {
        $countryId = strtoupper(trim((string)$this->countryId));
        $postcode = strtoupper(preg_replace('/[^a-z0-9]/i', '', (string)$this->postcode));
        if ($countryId === 'US') {
            $postcode = substr($postcode, 0, 5);
        }

        return implode('|', [
            $this->normalizeText(implode(' ', $this->street)),
            $this->normalizeText((string)$this->city),
            $this->regionId !== null ? 'id:' . $this->regionId : $this->normalizeText((string)$this->region),
            $postcode,
            $countryId,
        ]);
    }

    public function isSamePlaceAs(ShippingAddress $other): bool
    {
        return $this->getFingerprint() === $other->getFingerprint();
    }

    private function normalizeText(string $value): string
    {
        $value = preg_replace('/[^a-z0-9]+/', ' ', strtolower($value));
        return trim(preg_replace('/\s+/', ' ', $value));
    }
}
