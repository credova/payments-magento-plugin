<?php

namespace Magento\Framework\Exception;

class LocalizedException extends \Exception
{
    public function __construct(\Magento\Phrase $phrase, ?\Exception $cause = null, $code = 0)
    {
        parent::__construct($phrase->render(), (int) $code, $cause);
    }
}
