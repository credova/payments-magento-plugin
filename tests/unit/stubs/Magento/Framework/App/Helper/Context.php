<?php

namespace Magento\Framework\App\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;

class Context
{
    public function getScopeConfig(): ScopeConfigInterface
    {
        throw new \LogicException('Mock this method in tests that read config.');
    }
}
