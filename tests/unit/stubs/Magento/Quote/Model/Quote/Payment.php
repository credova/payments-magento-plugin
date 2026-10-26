<?php

namespace Magento\Quote\Model\Quote;

use Magento\Payment\Model\InfoInterface;

// Same additional-information contract as Magento's Payment\Model\Info: an array replaces it all.
class Payment implements InfoInterface
{
    private array $additionalInformation = [];
    private $method;

    public function getAdditionalInformation($key = null)
    {
        if ($key === null) {
            return $this->additionalInformation;
        }
        return $this->additionalInformation[$key] ?? null;
    }

    public function unsAdditionalInformation($key = null)
    {
        if ($key === null) {
            $this->additionalInformation = [];
        } else {
            unset($this->additionalInformation[$key]);
        }
        return $this;
    }

    public function getMethod()
    {
        return $this->method;
    }

    public function setMethod($method)
    {
        $this->method = $method;
        return $this;
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
