<?php

namespace Magento\Sales\Api\Data;

interface OrderAddressInterface
{
    public function getStreet();

    public function getCity();

    public function getRegionId();

    public function getRegion();

    public function getPostcode();

    public function getCountryId();
}
