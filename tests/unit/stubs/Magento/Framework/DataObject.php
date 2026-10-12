<?php

namespace Magento\Framework;

// The parts of Magento's DataObject that the plugin uses: keyed data with getData() and getDataByKey().
class DataObject
{
    protected $_data;

    public function __construct(array $data = [])
    {
        $this->_data = $data;
    }

    public function getData($key = '')
    {
        if ($key === '') {
            return $this->_data;
        }
        return $this->_data[$key] ?? null;
    }

    public function setData($key, $value = null)
    {
        if (is_array($key)) {
            $this->_data = $key;
        } else {
            $this->_data[$key] = $value;
        }
        return $this;
    }

    public function getDataByKey($key)
    {
        return $this->_data[$key] ?? null;
    }
}
