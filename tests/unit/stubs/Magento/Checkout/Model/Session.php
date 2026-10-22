<?php

namespace Magento\Checkout\Model;

class Session
{
    private array $data = [];

    public function getData($key = '', $index = null)
    {
        return $key === '' ? $this->data : ($this->data[$key] ?? null);
    }

    public function setData($key, $value = null)
    {
        $this->data[$key] = $value;
        return $this;
    }
}
