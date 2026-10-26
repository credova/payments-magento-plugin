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
use PublicSquare\Payments\Helper\Config;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Model\CardResolver;
use PublicSquare\Payments\Model\CvvRecollection\RequirementResolver;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddressHistory;
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
        $cardResolver = new CardResolver(
            $this->tokenManagement,
            new RequirementResolver($this->createMock(Config::class), $this->createMock(ShippingAddressHistory::class)),
            new Logger(),
        );
        $this->renewal = new SubscriptionRenewal($this->quoteRepository, $cardResolver, $this->logger);
    }

    public static function unmarkedPayments(): array
    {
        return [
            'no mark' => [null],
            'a mark that is not an array' => ['pmt_first'],
            'a mark with no payment ID' => [['cardId' => 'card_1']],
        ];
    }

    #[DataProvider('unmarkedPayments')]
    public function testHasNoInitialPaymentFor(mixed $mark): void
    {
        $payment = (new Interceptor())->setAdditionalInformation(SubscriptionRenewal::INITIAL_PAYMENT_KEY, $mark);

        $this->assertNull($this->renewal->getInitialPaymentId($payment, 'card_1'));
    }

    public static function renewalPayments(): array
    {
        return [
            'saved card' => [Config::VAULT_CODE, ['public_hash' => 'hash_abc']],
            'card ID' => [Config::CODE, ['cardId' => 'card_1']],
            'payment method nonce' => [Config::CODE, ['payment_method_nonce' => 'card_1']],
        ];
    }

    #[DataProvider('renewalPayments')]
    public function testSavesTheMarkOnTheQuotePayment(string $method, array $renewalPayment): void
    {
        $quote = $this->quote($renewalPayment, $method);
        $this->quoteRepository->expects($this->once())->method('save')->with($quote);

        // A refund moved last_trans_id to the refund's ID; cc_trans_id still has the payment.
        $this->renewal->markAsRenewal($quote, $this->order('pmt_first', 'card_1', 'pmt_first-refund'));

        // Magento copies the quote payment's additional information to the order payment.
        $orderPayment = (new Interceptor())->setAdditionalInformation(
            $quote->getPayment()->getAdditionalInformation(),
        );
        $this->assertSame('pmt_first', $this->renewal->getInitialPaymentId($orderPayment, 'card_1'));
    }

    /** A redelivered queue message reuses the attempt's key; a retry marks the quote again and gets a new one. */
    public function testGivesEachMarkedAttemptItsOwnIdempotencyKey(): void
    {
        $quote = $this->quote(['cardId' => 'card_1']);

        $this->renewal->markAsRenewal($quote, $this->order('pmt_first', 'card_1'));
        $firstKey = $quote->getPayment()->getAdditionalInformation('idempotencyKey');
        $this->renewal->markAsRenewal($quote, $this->order('pmt_first', 'card_1'));

        $this->assertNotEmpty($firstKey);
        $this->assertNotSame($firstKey, $quote->getPayment()->getAdditionalInformation('idempotencyKey'));
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
        $this->assertRefuses($this->quote(['cardId' => 'card_1']), $this->order($ccTransId, $cardId, $lastTransId));
    }

    public static function quotesWithNoPublicSquareCard(): array
    {
        return [
            'no payment set' => [Config::CODE, []],
            'a saved card the customer does not have' => [Config::VAULT_CODE, ['public_hash' => 'hash_unknown']],
            'a saved card the customer deleted' => [Config::VAULT_CODE, ['public_hash' => 'hash_deleted']],
            "another payment method's saved card" => [Config::VAULT_CODE, ['public_hash' => 'hash_braintree']],
            'another payment method' => ['braintree_cc_vault', ['public_hash' => 'hash_abc']],
        ];
    }

    #[DataProvider('quotesWithNoPublicSquareCard')]
    public function testRefusesAQuoteWithNoPublicSquareCard(string $method, array $renewalPayment): void
    {
        $this->assertRefuses($this->quote($renewalPayment, $method), $this->order('pmt_first', 'card_1'));
    }

    public function testRefusesARenewalOfAnotherCard(): void
    {
        $this->assertRefuses($this->quote(['cardId' => 'card_replacement']), $this->order('pmt_first', 'card_1'));
    }

    /** Expects markAsRenewal() to refuse, and to leave the quote unmarked and unsaved. */
    private function assertRefuses(Quote $quote, OrderInterface $initialOrder): void
    {
        $this->quoteRepository->expects($this->never())->method('save');
        try {
            $this->renewal->markAsRenewal($quote, $initialOrder);
            $this->fail('markAsRenewal() marked the quote.');
        } catch (LocalizedException) {
            $this->assertNull($quote->getPayment()->getAdditionalInformation(SubscriptionRenewal::INITIAL_PAYMENT_KEY));
        }
    }

    /**
     * Customer 42's renewal quote, whose payment has $additionalInformation. Customer 42 saved card_1 as hash_abc,
     * deleted hash_deleted, and saved hash_braintree with another payment method.
     */
    private function quote(array $additionalInformation, string $method = Config::CODE): Quote
    {
        $tokens = [
            'hash_abc' => $this->token(true, Config::CODE),
            'hash_deleted' => $this->token(false, Config::CODE),
            'hash_braintree' => $this->token(true, 'braintree'),
        ];
        $this->tokenManagement->method('getByPublicHash')
            ->willReturnCallback(fn ($hash, $customerId) => $customerId === 42 ? ($tokens[$hash] ?? null) : null);
        $payment = (new Payment())->setMethod($method)->setAdditionalInformation($additionalInformation);
        $quote = $this->createMock(Quote::class);
        $quote->method('getCustomerId')->willReturn(42);
        $quote->method('getPayment')->willReturn($payment);
        return $quote;
    }

    private function token(bool $active, string $method): PaymentTokenInterface
    {
        $token = $this->createMock(PaymentTokenInterface::class);
        $token->method('getGatewayToken')->willReturn('card_1');
        $token->method('getIsActive')->willReturn($active);
        $token->method('getPaymentMethodCode')->willReturn($method);
        return $token;
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
