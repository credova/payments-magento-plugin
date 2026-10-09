<?php

namespace PublicSquare\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use PublicSquare\Payments\Model\CvvRecollection\CvvRequestStore;

/**
 * Removes a cart's CVV requests once its order is placed; they only apply to that checkout.
 */
class ClearCvvRequestsObserver implements ObserverInterface
{
    private CvvRequestStore $requestStore;

    public function __construct(CvvRequestStore $requestStore)
    {
        $this->requestStore = $requestStore;
    }

    public function execute(Observer $observer)
    {
        $quote = $observer->getEvent()->getQuote();
        if ($quote && $quote->getId()) {
            $this->requestStore->clearForQuote((int)$quote->getId());
        }
    }
}
