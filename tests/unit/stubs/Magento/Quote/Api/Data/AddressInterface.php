<?php

namespace Magento\Quote\Api\Data;

interface AddressInterface
{
    public function getStreet();

    public function getCity();

    public function getRegionId();

    public function getRegion();

    public function getPostcode();

    public function getCountryId();
}
