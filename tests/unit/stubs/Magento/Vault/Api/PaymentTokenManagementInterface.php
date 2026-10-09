<?php

namespace Magento\Vault\Api;

interface PaymentTokenManagementInterface
{
    public function getByPublicHash($hash, $customerId);
}
