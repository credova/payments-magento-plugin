<?php

namespace PublicSquare\Payments\Test\Unit\Api\Authenticated;

use Laminas\Http\ClientFactory;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Api\Authenticated\PaymentCancel;
use PublicSquare\Payments\Api\Authenticated\PaymentCapture;
use PublicSquare\Payments\Api\Authenticated\PaymentUpdate;
use PublicSquare\Payments\Exception\ApiFailedResponseException;
use PublicSquare\Payments\Helper\Api;
use PublicSquare\Payments\Helper\Config;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Test\Unit\Api\FakeHttpClient;

/** The requests that act on an existing payment: capture an authorization, cancel it, or set its order id. */
class PaymentFollowUpRequestsTest extends TestCase
{
    private FakeHttpClient $client;

    protected function setUp(): void
    {
        $this->client = new FakeHttpClient('{"id":"pmt_1","status":"succeeded"}');
    }

    public function testCaptureSendsTheAmountInWholeCents(): void
    {
        $this->capture(19.99)->getResponseData();

        $this->assertSame('https://api.publicsquare.com/payments/capture', $this->client->uri);
        $this->assertSame('POST', $this->client->method);
        $this->assertSame(['amount' => 1999, 'payment_id' => 'pmt_1', 'external_id' => '100000123'], $this->client->json());
    }

    public function testCancelSendsThePaymentId(): void
    {
        $this->client = new FakeHttpClient('{"id":"pmt_1","status":"cancelled"}');

        $this->cancel()->getResponseData();

        $this->assertSame('https://api.publicsquare.com/payments/cancel', $this->client->uri);
        $this->assertSame(['payment_id' => 'pmt_1'], $this->client->json());
    }

    public function testCancelFailsUnlessThePaymentIsCancelled(): void
    {
        $this->expectException(ApiFailedResponseException::class);
        $this->expectExceptionMessage('The payment could not be successfully canceled.');

        $this->cancel()->getResponseData();
    }

    public function testUpdateSetsTheOrderIdOnThePayment(): void
    {
        $this->update()->getResponseData();

        $this->assertSame('https://api.publicsquare.com/payments/pmt_1', $this->client->uri);
        $this->assertSame(['external_id' => '100000123'], $this->client->json());
    }

    private function capture(float $amount): PaymentCapture
    {
        return new PaymentCapture($this->clientFactory(), $this->config(), new Logger(), $this->createMock(Api::class),
            $amount, 'pmt_1', '100000123');
    }

    private function cancel(): PaymentCancel
    {
        return new PaymentCancel($this->clientFactory(), $this->config(), new Logger(), 'pmt_1');
    }

    private function update(): PaymentUpdate
    {
        return new PaymentUpdate($this->clientFactory(), $this->config(), new Logger(), 'pmt_1', '100000123');
    }

    private function clientFactory(): ClientFactory
    {
        $clientFactory = $this->createMock(ClientFactory::class);
        $clientFactory->method('create')->willReturn($this->client);
        return $clientFactory;
    }

    private function config(): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('getUrii')->willReturn('https://api.publicsquare.com');
        return $config;
    }
}
