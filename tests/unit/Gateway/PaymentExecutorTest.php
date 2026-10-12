<?php

namespace PublicSquare\Payments\Test\Unit\Gateway;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Quote\Model\QuoteRepository;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order\Payment\Interceptor;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Model\CreditCardTokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Api\Authenticated\PaymentCancel;
use PublicSquare\Payments\Api\Authenticated\PaymentCancelFactory;
use PublicSquare\Payments\Api\Authenticated\PaymentCapture;
use PublicSquare\Payments\Api\Authenticated\PaymentCaptureFactory;
use PublicSquare\Payments\Api\Authenticated\PaymentCreate;
use PublicSquare\Payments\Api\Authenticated\PaymentCreateFactory;
use PublicSquare\Payments\Api\Authenticated\PaymentRefundFactory;
use PublicSquare\Payments\Api\Authenticated\PaymentUpdate;
use PublicSquare\Payments\Api\Authenticated\PaymentUpdateFactory;
use PublicSquare\Payments\Gateway\PaymentExecutor;
use PublicSquare\Payments\Logger\Logger;

/**
 * PaymentExecutor runs the money steps. These tests cover authorize, capture, cancel, and setting the order id. The PSQ request classes are mocked at their factories; PaymentCreateTest and
 * PaymentFollowUpRequestsTest cover what those classes send.
 */
class PaymentExecutorTest extends TestCase
{
    private const RESPONSE = [
        'id' => 'pmt_1',
        'status' => 'requires_capture',
        'fraud_details' => ['decision' => 'accept', 'rules' => [['rule_id' => '7']]],
        'payment_method' => ['card' => ['id' => 'card_1', 'last4' => '4242', 'brand' => 'visa', 'exp_month' => '4',
            'exp_year' => '2030', 'avs_code' => 'Y', 'cvv2_reply' => 'M']],
    ];

    private array $factories = [];
    private RecordingLogger $logger;
    private QuoteRepository $quoteRepository;
    private TransactionRepositoryInterface $transactions;
    private CreditCardTokenFactory $tokenFactory;
    private PaymentTokenRepositoryInterface $tokenRepository;
    private PaymentTokenManagementInterface $tokenManagement;
    private EncryptorInterface $encryptor;

    protected function setUp(): void
    {
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_USER_AGENT']);
        foreach ([PaymentCreateFactory::class, PaymentCaptureFactory::class, PaymentCancelFactory::class,
            PaymentUpdateFactory::class, PaymentRefundFactory::class] as $class) {
            $this->factories[$class] = $this->createMock($class);
        }
        $this->logger = new RecordingLogger();
        $this->quoteRepository = $this->createMock(QuoteRepository::class);
        $this->transactions = $this->createMock(TransactionRepositoryInterface::class);
        $this->tokenFactory = $this->createMock(CreditCardTokenFactory::class);
        $this->tokenRepository = $this->createMock(PaymentTokenRepositoryInterface::class);
        $this->tokenManagement = $this->createMock(PaymentTokenManagementInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
    }

    // ---- Create the payment (authorize, or authorize and capture) ----

    public static function captureModes(): array
    {
        return ['authorize' => ['executeAuthorize', false], 'authorize and capture' => ['executeAuthorizeCapture', true]];
    }

    #[DataProvider('captureModes')]
    public function testCreatesThePaymentFromTheCheckoutData(string $method, bool $capture): void
    {
        [$payment, $billing, $shipping] = $this->checkoutPayment();
        $request = $this->expectRequest(PaymentCreateFactory::class, PaymentCreate::class, self::RESPONSE);

        $this->executor()->$method($this->subject($payment));

        $this->assertSame([
            'idempotencyKey' => '1700000000000abc',
            'amount' => 19.99,
            'cardId' => 'card_1',
            'capture' => $capture,
            'phone' => '555-123-4567',
            'email' => 'jane@example.com',
            'shippingAddress' => $shipping,
            'billingAddress' => $billing,
            'externalId' => '100000123',
            'deviceInformation' => ['ip_address' => '203.0.113.7'],
        ], $request->data);
    }

    public function testUsesTheBillingEmailAndNoShippingForAGuestVirtualCart(): void
    {
        [$payment] = $this->checkoutPayment(order: ['customer_email' => null], virtual: true);
        $request = $this->expectRequest(PaymentCreateFactory::class, PaymentCreate::class, self::RESPONSE);

        $this->executor()->executeAuthorize($this->subject($payment));

        $this->assertSame('billing@example.com', $request->data['email']);
        $this->assertNull($request->data['shippingAddress']);
    }

    public static function unpayableCheckouts(): array
    {
        return [
            'no card' => [['cardId' => null], [], 'Card ID not found'],
            'no email' => [[], ['customer_email' => null, 'billing_email' => null],
                'An email address is required to complete your order.'],
        ];
    }

    #[DataProvider('unpayableCheckouts')]
    public function testRefusesToCreateAPaymentWithout(array $info, array $order, string $message): void
    {
        [$payment] = $this->checkoutPayment(info: $info, order: $order);
        $this->factories[PaymentCreateFactory::class]->expects($this->never())->method('create');

        $this->expectException(CommandException::class);
        $this->expectExceptionMessage($message);
        $this->executor()->executeAuthorize($this->subject($payment));
    }

    // ---- Record the PSQ answer on the order payment ----

    public function testRecordsThePaymentResponseOnTheOrderPayment(): void
    {
        [$payment] = $this->checkoutPayment();
        $this->expectRequest(PaymentCreateFactory::class, PaymentCreate::class, self::RESPONSE);

        $this->executor()->executeAuthorize($this->subject($payment));

        $this->assertSame(self::RESPONSE, $payment->getAdditionalInformation('raw_details_info'));
        $this->assertSame('accept', $payment->getAdditionalInformation('fraud_decision'));
        $this->assertSame([['rule_id' => '7']], $payment->getAdditionalInformation('fraud_rules'));
        $this->assertSame('Y', $payment->getAdditionalInformation('avsCode'));
        $this->assertSame('M', $payment->getAdditionalInformation('cvv2Reply'));
        $this->assertSame(['pmt_1', 'pmt_1', 'pmt_1'], [$payment->getLastTransId(), $payment->getTransactionId(),
            $payment->getCcTransId()]);
        $this->assertSame(['4242', 'visa', '4', '2030'], [$payment->getCcLast4(), $payment->getCcType(),
            $payment->getCcExpMonth(), $payment->getCcExpYear()]);
        // An authorization stays open for the capture.
        $this->assertSame(0, $payment->getIsTransactionClosed());
    }

    public function testClosesTheTransactionOfACapturedPayment(): void
    {
        [$payment] = $this->checkoutPayment();
        $this->expectRequest(PaymentCreateFactory::class, PaymentCreate::class, ['status' => 'succeeded'] + self::RESPONSE);

        $this->executor()->executeAuthorizeCapture($this->subject($payment));

        $this->assertSame(1, $payment->getIsTransactionClosed());
    }

    // ---- Capture and cancel an authorization ----

    public function testCapturesAnAuthorizedPayment(): void
    {
        $payment = $this->authorizedPayment('requires_capture');
        $request = $this->expectRequest(PaymentCaptureFactory::class, PaymentCapture::class,
            ['status' => 'succeeded'] + self::RESPONSE);

        $this->executor()->executeCapture($this->subject($payment));

        $this->assertSame(['paymentId' => 'pmt_1', 'amount' => 19.99, 'externalId' => '100000123'], $request->data);
        $this->assertSame(1, $payment->getIsTransactionClosed());
    }

    public function testDoesNotCaptureAPaymentThatIsNotWaitingForCapture(): void
    {
        $payment = $this->authorizedPayment('succeeded');
        $this->factories[PaymentCaptureFactory::class]->expects($this->never())->method('create');

        $this->executor()->executeCapture($this->subject($payment));

        $this->assertStringContainsString("status is not 'requires_capture'", $this->logger->lastMessage('warning'));
    }

    public function testCancelsAndRefusesToCaptureAPaymentFromAnotherGateway(): void
    {
        $payment = $this->authorizedPayment('requires_capture', 'ch_1');
        $cancel = $this->expectRequest(PaymentCancelFactory::class, PaymentCancel::class, ['status' => 'cancelled']);

        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('it is not possible to invoice this order because the payment is still pending');
        try {
            $this->executor()->executeCapture($this->subject($payment));
        } finally {
            $this->assertSame(['paymentId' => 'ch_1'], $cancel->data);
        }
    }

    public function testCancelsAnAuthorization(): void
    {
        $payment = $this->authorizedPayment('requires_capture');
        $cancel = $this->expectRequest(PaymentCancelFactory::class, PaymentCancel::class, ['status' => 'cancelled']);

        $this->executor()->executeCancel($this->subject($payment));

        $this->assertSame(['paymentId' => 'pmt_1'], $cancel->data);
        $this->assertSame(['status' => 'cancelled'], $payment->getAdditionalInformation('raw_details_info'));
        $this->assertSame(1, $payment->getIsTransactionClosed());
    }

    public function testSetsTheOrderIdOnAPsqPayment(): void
    {
        $update = $this->expectRequest(PaymentUpdateFactory::class, PaymentUpdate::class, null);

        $this->executor()->executeUpdate($this->orderEvent($this->authorizedPayment('succeeded')));

        $this->assertSame(['paymentId' => 'pmt_1', 'externalId' => '100000123'], $update->data);
    }

    // ---- Helpers ----

    private function executor(): PaymentExecutor
    {
        $store = new class {
            public function getWebsiteId() { return 3; }
        };
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $remoteAddress = $this->createMock(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturn('203.0.113.7');

        return new PaymentExecutor(
            $this->quoteRepository, $this->factories[PaymentCreateFactory::class], $this->tokenFactory, $storeManager,
            $this->tokenRepository, $this->tokenManagement, $this->encryptor, $this->logger, $this->transactions,
            $this->factories[PaymentCaptureFactory::class], $this->factories[PaymentCancelFactory::class],
            $this->factories[PaymentUpdateFactory::class], $this->factories[PaymentRefundFactory::class],
            $remoteAddress,
        );
    }

    /** Makes the factory return a request whose response is $response; the returned object records the factory data. */
    private function expectRequest(string $factory, string $requestClass, ?array $response): object
    {
        $recorded = new \stdClass();
        $recorded->data = null;
        $request = $this->createMock($requestClass);
        $request->method('getResponseData')->willReturn($response ?? []);
        $this->factories[$factory]->expects($this->once())->method('create')
            ->willReturnCallback(function (array $data) use ($recorded, $request) {
                $recorded->data = $data;
                return $request;
            });
        return $recorded;
    }

    private function subject(Interceptor $payment): array
    {
        $paymentDO = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDO->method('getPayment')->willReturn($payment);
        return ['payment' => $paymentDO, 'amount' => 19.99];
    }

    /** A new-card checkout payment; returns the payment and the quote's billing and shipping addresses. */
    private function checkoutPayment(array $info = [], array $order = [], bool $virtual = false): array
    {
        $billing = new FakeRecord(['telephone' => '555-123-4567', 'email' => 'billing@example.com']);
        $shipping = new FakeRecord(['firstname' => 'Jane', 'lastname' => 'Doe', 'id' => 9]);
        $this->quoteRepository->method('get')->with(77)->willReturn(new FakeRecord(
            ['id' => 77, 'billing_address' => $billing, 'shipping_address' => $shipping, 'is_virtual' => $virtual],
        ));
        $order += ['billing_email' => 'billing@example.com'];
        $payment = (new Interceptor())->setOrder($this->order($order + ['billing_address' => new FakeRecord(
            ['email' => $order['billing_email']],
        )]));
        foreach ($info + ['cardId' => 'card_1', 'idempotencyKey' => '1700000000000abc', 'saveCard' => false] as $k => $v) {
            $payment->setAdditionalInformation($k, $v);
        }
        return [$payment, $billing, $shipping];
    }

    /** A payment that PSQ already authorized ($status) under the Magento transaction $txnId. */
    private function authorizedPayment(string $status, string $txnId = 'pmt_1'): Interceptor
    {
        $payment = (new Interceptor())->setOrder($this->order([]))->setLastTransId($txnId);
        $payment->setAdditionalInformation('raw_details_info', ['status' => $status, 'amount' => 1999] + self::RESPONSE);
        $this->transactions->method('get')->with($txnId)->willReturn(new FakeRecord(['txn_id' => $txnId]));
        return $payment;
    }

    private function order(array $data): FakeRecord
    {
        return new FakeRecord($data + ['customer_id' => 42, 'quote_id' => 77, 'customer_email' => 'jane@example.com',
            'increment_id' => '100000123', 'id' => 5]);
    }

    /** An observer for an order event, as Magento dispatches it; the order holds $payment. */
    private function orderEvent(Interceptor $payment): Observer
    {
        $order = $payment->getOrder();
        $order->set('payment', $payment);
        $event = new class(['order' => $order]) extends Event {
            public function getOrder() { return $this->getData('order'); }
        };
        return new Observer(['event' => $event]);
    }
}

/** Answers getFooBar() from the 'foo_bar' key, like Magento's DataObject. */
class FakeRecord
{
    public function __construct(private array $data) {}

    public function set(string $key, $value): void { $this->data[$key] = $value; }

    public function __call($method, $args)
    {
        $key = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', substr($method, 3)));
        return $this->data[$key] ?? null;
    }
}

/** Keeps its log lines through withName(), which the executor calls in its constructor. */
class RecordingLogger extends Logger
{
    public function withName(string $name) { return $this; }

    public function lastMessage(string $level): ?string
    {
        $levels = array_filter($this->messages, fn ($m) => ($m['level'] ?? null) === $level);
        return end($levels)[0] ?? null;
    }

    public function warning(...$args) { $this->messages[] = $args + ['level' => 'warning']; }
    public function info(...$args) { $this->messages[] = $args + ['level' => 'info']; }
    public function error(...$args) { $this->messages[] = $args + ['level' => 'error']; }
}
