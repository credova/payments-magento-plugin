<?php

namespace Magento\Payment\Model;

interface InfoInterface
{
    public function getAdditionalInformation($key = null);

    public function setAdditionalInformation($key, $value = null);
}
