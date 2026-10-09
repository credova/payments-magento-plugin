<?php

namespace PublicSquare\Payments\Test\Unit\Observer;

use Magento\Framework\Event\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Model\CvvRecollection\CvvRequestStore;
use PublicSquare\Payments\Observer\ClearCvvRequestsObserver;

class ClearCvvRequestsObserverTest extends TestCase
{
    public function testClearsTheSubmittedCartsCvvRequests(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(42);
        $store = $this->createMock(CvvRequestStore::class);
        $store->expects($this->once())->method('clearForQuote')->with(42);

        (new ClearCvvRequestsObserver($store))->execute($this->observerFor($quote));
    }

    public function testDoesNothingWithoutAQuote(): void
    {
        $store = $this->createMock(CvvRequestStore::class);
        $store->expects($this->never())->method('clearForQuote');

        (new ClearCvvRequestsObserver($store))->execute($this->observerFor(null));
    }

    private function observerFor(?Quote $quote): Observer
    {
        $event = $this->createMock(Event::class);
        $event->method('getQuote')->willReturn($quote);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);
        return $observer;
    }
}
