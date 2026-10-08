<?php

namespace Magento\Quote\Model\Quote;

use Magento\Payment\Model\InfoInterface;

// Same additional-information contract as Magento's Payment\Model\Info: an array replaces it all.
class Payment implements InfoInterface
{
    private array $additionalInformation = [];

    public function getAdditionalInformation($key = null)
    {
        if ($key === null) {
            return $this->additionalInformation;
        }
        return $this->additionalInformation[$key] ?? null;
    }

    public function setAdditionalInformation($key, $value = null)
    {
        if (is_array($key) && $value === null) {
            $this->additionalInformation = $key;
        } else {
            $this->additionalInformation[$key] = $value;
        }
        return $this;
    }
}
