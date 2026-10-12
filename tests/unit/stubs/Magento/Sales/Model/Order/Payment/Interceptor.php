<?php

namespace Magento\Sales\Model\Order\Payment;

use Magento\Payment\Model\InfoInterface;

/**
 * The order payment that the gateway gets. Like Magento's, it keeps any getX()/setX() value, and an array given to
 * setAdditionalInformation() replaces all of the additional information.
 */
class Interceptor implements InfoInterface
{
    private array $data = [];
    private array $additionalInformation = [];

    public function getAdditionalInformation($key = null)
    {
        return $key === null ? $this->additionalInformation : ($this->additionalInformation[$key] ?? null);
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

    public function __call($method, $args)
    {
        $key = lcfirst(substr($method, 3));
        if (str_starts_with($method, 'set')) {
            $this->data[$key] = $args[0] ?? null;
            return $this;
        }
        return $this->data[$key] ?? null;
    }
}
