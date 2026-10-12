<?php

namespace Magento\Vault\Api;

interface PaymentTokenManagementInterface
{
    /** Returns null when no token has that hash for that customer, as Magento's does. */
    public function getByPublicHash($hash, $customerId);
}
