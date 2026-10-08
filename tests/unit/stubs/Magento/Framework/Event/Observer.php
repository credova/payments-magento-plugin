<?php

namespace Magento\Framework\Event;

use Magento\Framework\DataObject;

class Observer extends DataObject
{
    public function getEvent()
    {
        return $this->getData('event');
    }
}
