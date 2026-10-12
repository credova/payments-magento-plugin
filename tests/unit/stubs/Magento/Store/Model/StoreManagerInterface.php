<?php

namespace Magento\Store\Model;

interface StoreManagerInterface
{
    public function getStore($storeId = null);
}
