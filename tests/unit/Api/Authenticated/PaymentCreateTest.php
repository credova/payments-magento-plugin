<?php

namespace PublicSquare\Payments\Test\Unit\Api\Authenticated;

use Laminas\Http\ClientFactory;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Api\Authenticated\PaymentCreate;
use PublicSquare\Payments\Exception\ApiDeclinedResponseException;
use PublicSquare\Payments\Exception\ApiFailedResponseException;
use PublicSquare\Payments\Exception\ApiRejectedResponseException;
use PublicSquare\Payments\Helper\Config;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Test\Unit\Api\FakeHttpClient;

/**
 * PaymentCreate is the charge request. These tests read what goes over the wire to PublicSquare, through the
 * shared ApiRequestAbstract: URI, headers, JSON body, and how the response status is handled.
 */
class PaymentCreateTest extends TestCase
{
    private FakeHttpClient $client;
    private Config $config;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->client = new FakeHttpClient('{"id":"pmt_1","status":"requires_capture"}');
        $this->config = $this->createMock(Config::class);
        $this->config->method('getUrii')->willReturn('https://api.publicsquare.com');
        $this->config->method('getSecretAPIKey')->willReturn('sk_test_key');
        $this->logger = new Logger();
    }

    public function testPostsThePaymentWithTheSecretKey(): void
    {
        $this->paymentCreate()->getResponseData();

        $this->assertSame('https://api.publicsquare.com/payments', $this->client->uri);
        $this->assertSame('POST', $this->client->method);
        $this->assertSame('application/json', $this->client->headers['Content-Type']);
        $this->assertSame('sk_test_key', $this->client->headers['X-API-KEY']);
    }

    public static function idempotencyKeys(): array
    {
        // The card id is not part of the hash, so a retry with a new card token keeps the same key.
        return [
            'with the order id' => ['100000123', '1700000000000abc-jane@example.com-authorize-100000123'],
            'without an order id' => ['', '1700000000000abc-jane@example.com-authorize'],
        ];
    }

    #[DataProvider('idempotencyKeys')]
    public function testSendsTheHashedIdempotencyKey(string $externalId, string $hashInput): void
    {
        $this->paymentCreate(['externalId' => $externalId])->getResponseData();

        $this->assertSame(substr(hash('sha256', $hashInput), 0, 50), $this->client->headers['IDEMPOTENCY-KEY']);
    }

    public function testSendsNoIdempotencyKeyWhenCheckoutGaveNone(): void
    {
        $this->paymentCreate(['idempotencyKey' => null])->getResponseData();

        $this->assertArrayNotHasKey('IDEMPOTENCY-KEY', $this->client->headers);
    }

    public static function amounts(): array
    {
        return ['19.99' => [19.99, 1999], '4.35' => [4.35, 435], '1.15' => [1.15, 115], '0.29' => [0.29, 29]];
    }

    #[DataProvider('amounts')]
    public function testSendsTheAmountAsWholeCents(float $amount, int $cents): void
    {
        $this->paymentCreate(['amount' => $amount])->getResponseData();

        // assertSame on the decoded int fails for a float such as 1998.9999999999998 or 1999.0.
        $this->assertSame($cents, $this->client->json()['amount']);
    }

    public function testSendsTheCardCustomerAndAddresses(): void
    {
        $this->paymentCreate()->getResponseData();

        $body = $this->client->json();
        $this->assertSame(['card' => 'card_1'], $body['payment_method']);
        $this->assertTrue($body['capture']);
        $this->assertSame('USD', $body['currency']);
        $this->assertSame('100000123', $body['external_id']);
        $this->assertSame(
            ['external_id' => '', 'business_name' => '', 'first_name' => 'Jane', 'last_name' => 'Doe',
                'email' => 'jane@example.com', 'phone' => '555-123-4567'],
            $body['customer'],
        );
        $expectedAddress = ['address_line_1' => '1 Main St', 'address_line_2' => 'Apt 2', 'city' => 'Austin',
            'state' => 'TX', 'postal_code' => '78701', 'country' => 'US'];
        $this->assertSame($expectedAddress, $body['billing_details']);
        $this->assertSame($expectedAddress, $body['shipping_address']);
        $this->assertSame(['ip_address' => '203.0.113.7'], $body['device_information']);
    }

    public function testLeavesOutShippingAndDeviceDataWhenThereIsNone(): void
    {
        $this->paymentCreate([
            'billingAddress' => $this->address(['street' => ['1 Main St']]),
            'shippingAddress' => null,
            'deviceInformation' => null,
        ])->getResponseData();

        $body = $this->client->json();
        $this->assertSame('', $body['billing_details']['address_line_2']);
        $this->assertArrayNotHasKey('shipping_address', $body);
        $this->assertArrayNotHasKey('device_information', $body);
    }

    public static function phoneNumbers(): array
    {
        return [
            'US with country code' => ['+1 (555) 123-4567', '555-123-4567'],
            'dotted' => ['555.123.4567', '555-123-4567'],
            'too short to format' => ['12345', '12345'],
        ];
    }

    #[DataProvider('phoneNumbers')]
    public function testFormatsThePhoneNumber(string $raw, string $formatted): void
    {
        $this->assertSame($formatted, PaymentCreate::formatPhoneNumber($raw));
    }

    public function testCutsTheDynamicDescriptorToTheCardNetworkLimits(): void
    {
        $this->config->method('isPaymentDynamicDescriptorEnabled')->willReturn(true);
        $this->config->method('getPaymentDynamicDescriptorMerchant')->willReturn('A Very Long Merchant Name Inc');
        $this->config->method('getPaymentDynamicDescriptorMerchantContact')->willReturn('1-800-555-0100 ext 9');

        $this->paymentCreate()->getResponseData();

        $this->assertSame(
            ['merchant_name' => 'A Very Long Merchant N', 'merchant_contact' => '1-800-555-010'],
            $this->client->json()['dynamic_descriptor'],
        );
    }

    public function testSendsNoDynamicDescriptorWithoutAMerchantName(): void
    {
        $this->config->method('isPaymentDynamicDescriptorEnabled')->willReturn(true);
        $this->config->method('getPaymentDynamicDescriptorMerchant')->willReturn('');
        $this->config->method('getPaymentDynamicDescriptorMerchantContact')->willReturn('1-800-555-0100');

        $this->paymentCreate()->getResponseData();

        $this->assertArrayNotHasKey('dynamic_descriptor', $this->client->json());
    }

    public static function acceptedStatuses(): array
    {
        return ['authorized' => ['requires_capture'], 'captured' => ['succeeded']];
    }

    #[DataProvider('acceptedStatuses')]
    public function testReturnsTheResponseForAnAcceptedPayment(string $status): void
    {
        $this->client = new FakeHttpClient(json_encode(['id' => 'pmt_1', 'status' => $status]));

        $this->assertSame(['id' => 'pmt_1', 'status' => $status], $this->paymentCreate()->getResponseData());
    }

    public static function failedResponses(): array
    {
        return [
            'rejected' => [['status' => 'rejected'], ApiRejectedResponseException::class,
                'The payment could not be completed. Please verify your details and try again.'],
            'declined with a reason' => [['status' => 'declined', 'declined_reason' => 'insufficient_funds'],
                ApiDeclinedResponseException::class, 'The payment could not be processed. Reason: insufficient_funds'],
            'failed' => [['status' => 'failed'], ApiFailedResponseException::class, 'Something went wrong. Please try again.'],
            'an unknown status' => [['status' => 'pending'], ApiFailedResponseException::class,
                'The payment could not be completed. Please verify your details and try again.'],
        ];
    }

    #[DataProvider('failedResponses')]
    public function testThrowsWhenThePaymentIsNotAccepted(array $response, string $exception, string $message): void
    {
        $this->client = new FakeHttpClient(json_encode(['id' => 'pmt_1'] + $response));

        $this->expectException($exception);
        $this->expectExceptionMessage($message);
        $this->paymentCreate()->getResponseData();
    }

    public function testThrowsWhenTheResponseIsNotJson(): void
    {
        $this->client = new FakeHttpClient('<html>502 Bad Gateway</html>');

        $this->expectExceptionMessage('Something went wrong. Please try again.');
        $this->paymentCreate()->getResponseData();
    }

    public function testLogsOnlyTheSanitizedResponse(): void
    {
        $this->client = new FakeHttpClient(json_encode([
            'id' => 'pmt_1',
            'status' => 'succeeded',
            'amount' => 1999,
            'customer' => ['email' => 'jane@example.com', 'phone' => '555-123-4567'],
            'billing_details' => ['address_line_1' => '1 Main St'],
            'payment_method' => ['card' => ['last4' => '4242']],
        ]));

        $this->paymentCreate()->getResponseData();

        $logged = array_column(array_filter($this->logger->messages, fn ($m) => isset($m[1]['response'])), 1);
        $this->assertSame([['response' => ['id' => 'pmt_1', 'status' => 'succeeded', 'amount' => 1999]]], $logged);
    }

    public function testSendsTheRequestOnceAndReusesTheResponse(): void
    {
        $request = $this->paymentCreate();

        $request->getResponseData();
        $request->getResponseData();

        $this->assertSame(1, $this->client->sendCount);
    }

    private function paymentCreate(array $overrides = []): PaymentCreate
    {
        $clientFactory = $this->createMock(ClientFactory::class);
        $clientFactory->method('create')->willReturn($this->client);
        $args = $overrides + [
            'amount' => 19.99,
            'cardId' => 'card_1',
            'capture' => true,
            'phone' => '+1 (555) 123-4567',
            'email' => 'jane@example.com',
            'billingAddress' => $this->address(),
            'shippingAddress' => $this->address(),
            'idempotencyKey' => '1700000000000abc',
            'externalId' => '100000123',
            'deviceInformation' => ['ip_address' => '203.0.113.7'],
        ];

        return new PaymentCreate($clientFactory, $this->config, $this->logger, ...$args);
    }

    private function address(array $overrides = []): Address
    {
        return new Address($overrides + [
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'street' => ['1 Main St', 'Apt 2'],
            'city' => 'Austin',
            'region_code' => 'TX',
            'postcode' => '78701',
            'country_id' => 'US',
        ]);
    }
}
