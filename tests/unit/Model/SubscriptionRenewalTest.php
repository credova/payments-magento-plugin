<?php

namespace PublicSquare\Payments\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Payment\Interceptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Model\SubscriptionRenewal;

/**
 * SubscriptionRenewal remembers, by quote, which PublicSquare payment a renewal continues. PaymentExecutorTest covers
 * how the payment request uses it.
 */
class SubscriptionRenewalTest extends TestCase
{
    private Logger $logger;
    private SubscriptionRenewal $renewal;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(Logger::class);
        $this->renewal = new SubscriptionRenewal($this->logger);
    }

    public function testHasNoInitialPaymentForAnUnmarkedQuote(): void
    {
        $this->assertNull($this->renewal->getInitialPaymentId(77, 'card_1'));
    }

    public function testMarksTheQuoteAsARenewalOfTheInitialOrdersPayment(): void
    {
        // A refund moved last_trans_id to the refund's ID; cc_trans_id still has the payment.
        $this->renewal->markAsRenewal($this->quote(77), $this->order('pmt_first', 'card_1', 'pmt_first-refund'));

        $this->assertSame('pmt_first', $this->renewal->getInitialPaymentId(77, 'card_1'));
        $this->assertNull($this->renewal->getInitialPaymentId(78, 'card_1'));
    }

    public function testDoesNotSendARenewalOfAnotherCardAsRecurring(): void
    {
        $this->renewal->markAsRenewal($this->quote(77), $this->order('pmt_first', 'card_1'));
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->renewal->getInitialPaymentId(77, 'card_replacement'));
    }

    public static function ordersNotPaidThroughPublicSquare(): array
    {
        return [
            'another gateway' => [null, null, 'ch_other_gateway'],
            'no PublicSquare card' => ['pmt_first', null, 'pmt_first'],
        ];
    }

    #[DataProvider('ordersNotPaidThroughPublicSquare')]
    public function testRefusesAnInitialOrderNotPaidThroughPublicSquare(
        ?string $ccTransId,
        ?string $cardId,
        string $lastTransId
    ): void {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Order 100000042 was not paid through PublicSquare, so it can\'t start a recurring payment.',
        );

        $this->renewal->markAsRenewal($this->quote(77), $this->order($ccTransId, $cardId, $lastTransId));
    }

    public function testRefusesAQuoteThatIsNotSaved(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Save the quote before marking it as a subscription renewal.');

        $this->renewal->markAsRenewal($this->quote(null), $this->order('pmt_first', 'card_1'));
    }

    private function quote(?int $id): CartInterface
    {
        $quote = $this->createMock(CartInterface::class);
        $quote->method('getId')->willReturn($id);
        return $quote;
    }

    /** An order whose payment PublicSquare answered with $cardId charged. */
    private function order(?string $ccTransId, ?string $cardId, ?string $lastTransId = null): OrderInterface
    {
        $payment = (new Interceptor())->setCcTransId($ccTransId)->setLastTransId($lastTransId ?? $ccTransId);
        if ($cardId) {
            $payment->setAdditionalInformation('raw_details_info', ['payment_method' => ['card' => ['id' => $cardId]]]);
        }
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIncrementId')->willReturn('100000042');
        return $order;
    }
}
