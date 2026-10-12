<?php

namespace Magento\Quote\Model\Quote;

// The address getters the API requests read. Magento's Address returns street as an array of lines.
class Address
{
    private array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function getFirstname() { return $this->data['firstname'] ?? null; }
    public function getLastname() { return $this->data['lastname'] ?? null; }
    public function getStreet() { return $this->data['street'] ?? []; }
    public function getCity() { return $this->data['city'] ?? null; }
    public function getRegionCode() { return $this->data['region_code'] ?? null; }
    public function getPostcode() { return $this->data['postcode'] ?? null; }
    public function getCountryId() { return $this->data['country_id'] ?? null; }
}
