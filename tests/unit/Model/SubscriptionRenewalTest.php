<?php

namespace PublicSquare\Payments\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Payment\Interceptor;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Model\SubscriptionRenewal;

/** PaymentExecutorTest covers the payment request that reads this mark. */
class SubscriptionRenewalTest extends TestCase
{
    private CartRepositoryInterface $quoteRepository;
    private PaymentTokenManagementInterface $tokenManagement;
    private Logger $logger;
    private SubscriptionRenewal $renewal;

    protected function setUp(): void
    {
        $this->quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $this->tokenManagement = $this->createMock(PaymentTokenManagementInterface::class);
        $this->logger = $this->createMock(Logger::class);
        $this->renewal = new SubscriptionRenewal($this->quoteRepository, $this->tokenManagement, $this->logger);
    }

    public function testHasNoInitialPaymentForAnUnmarkedPayment(): void
    {
        $payment = new Interceptor();

        $this->assertNull($this->renewal->getInitialPaymentId($payment, 'card_1'));
    }

    public static function renewalPayments(): array
    {
        return [
            'saved card' => [['public_hash' => 'hash_abc']],
            'card ID' => [['cardId' => 'card_1']],
            'payment method nonce' => [['payment_method_nonce' => 'card_1']],
        ];
    }

    #[DataProvider('renewalPayments')]
    public function testSavesTheMarkOnTheQuotePayment(array $renewalPayment): void
    {
        $quote = $this->quote($renewalPayment);
        $this->quoteRepository->expects($this->once())->method('save')->with($quote);

        // A refund moved last_trans_id to the refund's ID; cc_trans_id still has the payment.
        $this->renewal->markAsRenewal($quote, $this->order('pmt_first', 'card_1', 'pmt_first-refund'));

        // Magento copies the quote payment's additional information to the order payment.
        $orderPayment = (new Interceptor())->setAdditionalInformation(
            $quote->getPayment()->getAdditionalInformation(),
        );
        $this->assertSame('pmt_first', $this->renewal->getInitialPaymentId($orderPayment, 'card_1'));
    }

    public function testSendsAPaymentWhoseCardChangedAfterTheMarkAsAOneOff(): void
    {
        $orderPayment = (new Interceptor())->setAdditionalInformation(
            SubscriptionRenewal::INITIAL_PAYMENT_KEY,
            ['paymentId' => 'pmt_first', 'cardId' => 'card_1'],
        );
        $this->logger->expects($this->once())->method('warning');

        $this->assertNull($this->renewal->getInitialPaymentId($orderPayment, 'card_replacement'));
    }

    public static function ordersNotPaidThroughPublicSquare(): array
    {
        return [
            'another gateway' => [null, null, 'ch_other_gateway'],
            'another gateway with a card ID' => ['ch_other_gateway', 'card_1', 'ch_other_gateway'],
            'no PublicSquare card' => ['pmt_first', null, 'pmt_first'],
        ];
    }

    #[DataProvider('ordersNotPaidThroughPublicSquare')]
    public function testRefusesAnInitialOrderNotPaidThroughPublicSquare(
        ?string $ccTransId,
        ?string $cardId,
        string $lastTransId
    ): void {
        $this->expectRefusal('Order 100000042 was not paid through PublicSquare, so it can\'t start a recurring payment.');

        $this->renewal->markAsRenewal($this->quote(['cardId' => 'card_1']), $this->order($ccTransId, $cardId, $lastTransId));
    }

    public static function quotesWithNoPublicSquareCard(): array
    {
        return [
            'no payment set' => [[]],
            'a saved card the customer does not have' => [['public_hash' => 'hash_unknown']],
        ];
    }

    #[DataProvider('quotesWithNoPublicSquareCard')]
    public function testRefusesAQuoteWithNoPublicSquareCard(array $renewalPayment): void
    {
        $this->expectRefusal('Set the quote\'s PublicSquare payment before marking it as a subscription renewal.');

        $this->renewal->markAsRenewal($this->quote($renewalPayment), $this->order('pmt_first', 'card_1'));
    }

    public function testRefusesARenewalOfAnotherCard(): void
    {
        $this->expectRefusal(
            'The renewal charges another card than order 100000042, so it can\'t continue its recurring payment.',
        );

        $this->renewal->markAsRenewal($this->quote(['cardId' => 'card_replacement']), $this->order('pmt_first', 'card_1'));
    }

    /** Expects markAsRenewal() to refuse with $message, and to leave the quote unmarked and unsaved. */
    private function expectRefusal(string $message): void
    {
        $this->quoteRepository->expects($this->never())->method('save');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($message);
    }

    /** Customer 42's renewal quote, whose payment has $additionalInformation. Customer 42 saved card_1 as hash_abc. */
    private function quote(array $additionalInformation): Quote
    {
        $token = $this->createMock(PaymentTokenInterface::class);
        $token->method('getGatewayToken')->willReturn('card_1');
        $this->tokenManagement->method('getByPublicHash')
            ->willReturnCallback(fn ($hash, $customerId) => $hash === 'hash_abc' && $customerId === 42 ? $token : null);
        $payment = (new Payment())->setAdditionalInformation($additionalInformation);
        $quote = $this->createMock(Quote::class);
        $quote->method('getCustomerId')->willReturn(42);
        $quote->method('getPayment')->willReturn($payment);
        return $quote;
    }

    /** An order paid with $cardId whose raw_details_info has no card, as after a capture or cancel. */
    private function order(?string $ccTransId, ?string $cardId, ?string $lastTransId = null): OrderInterface
    {
        $payment = (new Interceptor())->setCcTransId($ccTransId)->setLastTransId($lastTransId ?? $ccTransId);
        $payment->setAdditionalInformation('raw_details_info', ['id' => $ccTransId, 'status' => 'cancelled']);
        if ($cardId) {
            $payment->setAdditionalInformation('cardId', $cardId);
        }
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getIncrementId')->willReturn('100000042');
        return $order;
    }
}
