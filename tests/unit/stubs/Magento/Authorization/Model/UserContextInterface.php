<?php

namespace Magento\Authorization\Model;

interface UserContextInterface
{
    public const USER_TYPE_INTEGRATION = 1;
    public const USER_TYPE_ADMIN = 2;
    public const USER_TYPE_CUSTOMER = 3;
    public const USER_TYPE_GUEST = 4;

    public function getUserId();

    public function getUserType();
}
