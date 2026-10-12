<?php

namespace Magento\Payment\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Payment\Model\InfoInterface;

// Same contract as Magento's: reads the event's arguments and checks their types.
abstract class AbstractDataAssignObserver implements ObserverInterface
{
    const METHOD_CODE = 'method';
    const DATA_CODE = 'data';
    const MODEL_CODE = 'payment_model';

    protected function readPaymentModelArgument(Observer $observer)
    {
        return $this->readArgument($observer, static::MODEL_CODE, InfoInterface::class);
    }

    protected function readDataArgument(Observer $observer)
    {
        return $this->readArgument($observer, static::DATA_CODE, DataObject::class);
    }

    protected function readArgument(Observer $observer, $key, $type)
    {
        $argument = $observer->getEvent()->getDataByKey($key);
        if (!$argument instanceof $type) {
            throw new \LogicException($key . ' should be provided');
        }
        return $argument;
    }
}
