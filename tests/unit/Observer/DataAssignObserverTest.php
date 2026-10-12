<?php

namespace PublicSquare\Payments\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Quote\Model\Quote\Payment;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Observer\DataAssignObserver;

/**
 * Admin order create posts the tokenized card as payment[payment_method_nonce]. CaptureCommand reads it back with
 * getAdditionalInformation('payment_method_nonce').
 */
class DataAssignObserverTest extends TestCase
{
    public function testStoresTheNonceAndDeviceData(): void
    {
        $payment = $this->assign([
            'payment_method_nonce' => 'card_1',
            'device_data' => '{"ip":"203.0.113.7"}',
            'cardId' => 'not_copied_here',
        ]);

        $this->assertSame(
            ['payment_method_nonce' => 'card_1', 'device_data' => '{"ip":"203.0.113.7"}'],
            $payment->getAdditionalInformation(),
        );
    }

    public function testSkipsAKeyThatIsNotSent(): void
    {
        $payment = $this->assign(['payment_method_nonce' => 'card_1']);

        $this->assertSame(['payment_method_nonce' => 'card_1'], $payment->getAdditionalInformation());
    }

    /** Runs the observer the way Magento's payment_method_assign_data event does. */
    private function assign(array $additionalData): Payment
    {
        $payment = new Payment();
        $event = new Event([
            'data' => new DataObject(['additional_data' => $additionalData]),
            'payment_model' => $payment,
        ]);

        (new DataAssignObserver())->execute(new Observer(['event' => $event]));

        return $payment;
    }
}
