<?php

namespace Magento\Quote\Api;

interface CartRepositoryInterface
{
    public function get($cartId);

    public function getActiveForCustomer($customerId);
}
