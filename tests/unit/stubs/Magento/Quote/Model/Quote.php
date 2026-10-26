<?php

namespace Magento\Quote\Model;

use Magento\Quote\Api\Data\CartInterface;

class Quote implements CartInterface
{
    public function getId()
    {
        return null;
    }

    public function getStoreId()
    {
        return null;
    }

    public function isVirtual()
    {
        return false;
    }

    public function getShippingAddress()
    {
        return null;
    }

    public function getCustomerId()
    {
    }

    public function getPayment()
    {
    }
}
