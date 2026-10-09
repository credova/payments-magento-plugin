<?php

namespace Magento\Framework\App\Helper;

abstract class AbstractHelper
{
    protected $scopeConfig;

    public function __construct(Context $context)
    {
        // Same as Magento: helpers read config through the context's scope config.
        $this->scopeConfig = $context->getScopeConfig();
    }
}
