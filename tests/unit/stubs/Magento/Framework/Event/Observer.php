<?php

namespace Magento\Framework\Event;

class Observer
{
    public function getEvent(): Event
    {
        throw new \LogicException('Mock this method.');
    }
}
