<?php

namespace Magento\Quote\Api\Data;

interface PaymentInterface
{
    const KEY_ADDITIONAL_DATA = 'additional_data';

    public function getAdditionalInformation(): array;
    public function setAdditionalInformation(array $additionalData);

}