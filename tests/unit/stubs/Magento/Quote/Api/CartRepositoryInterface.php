<?php

namespace Magento\Quote\Api;

use Magento\Quote\Api\Data\CartInterface;

interface CartRepositoryInterface
{
    public function get($cartId);

    public function getActiveForCustomer($customerId);

    public function save(CartInterface $quote);
}
