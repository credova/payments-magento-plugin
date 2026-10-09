<?php

namespace PublicSquare\Payments\Test\Unit\Api\Authenticated;

use Laminas\Http\ClientFactory;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Api\Authenticated\PaymentCreate;
use PublicSquare\Payments\Exception\ApiRejectedResponseException;
use PublicSquare\Payments\Exception\CvvRecollectionRequiredException;
use PublicSquare\Payments\Helper\Config;
use PublicSquare\Payments\Logger\Logger;

class PaymentCreateTest extends TestCase
{
    public function testSendsRequireFreshCvcWhenGiven(): void
    {
        $request = $this->paymentCreate(['updated_after' => '2026-10-08T22:42:10Z', 'max_age_seconds' => 1800]);

        $this->assertSame(
            ['updated_after' => '2026-10-08T22:42:10Z', 'max_age_seconds' => 1800],
            $this->requestData($request)['require_fresh_cvc']
        );
    }

    public function testLeavesRequireFreshCvcOutByDefault(): void
    {
        $this->assertArrayNotHasKey('require_fresh_cvc', $this->requestData($this->paymentCreate()));
    }

    public static function cvvRecollectionRequiredBodies(): array
    {
        return [
            'top-level error_code' => [['error_code' => 'cvv_recollection_required', 'message' => 'x']],
            'top-level code' => [['code' => 'cvv_recollection_required']],
            'errors list' => [['errors' => [['code' => 'cvv_recollection_required', 'message' => 'x']]]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cvvRecollectionRequiredBodies')]
    public function testCvvRecollectionRequiredAsksForTheCvvAgain(array $body): void
    {
        $this->expectException(CvvRecollectionRequiredException::class);
        $this->expectExceptionMessage("Please re-enter your card's security code to continue.");

        $this->validate($this->paymentCreate(), $body);
    }

    public function testCvvMismatchHasItsOwnMessage(): void
    {
        $this->expectException(ApiRejectedResponseException::class);
        $this->expectExceptionMessage("The security code didn't match. Please check it and try again.");

        $this->validate($this->paymentCreate(), [
            'status' => 'rejected',
            'payment_method' => ['card' => ['cvv2_reply' => 'N']],
        ]);
    }

    public function testOtherRejectionsKeepTheGenericMessage(): void
    {
        $this->expectException(ApiRejectedResponseException::class);
        $this->expectExceptionMessage('The payment could not be completed. Please verify your details and try again.');

        $this->validate($this->paymentCreate(), [
            'status' => 'rejected',
            'payment_method' => ['card' => ['cvv2_reply' => 'M']],
        ]);
    }

    public function testSucceededPaymentsAreValid(): void
    {
        $this->assertTrue($this->validate($this->paymentCreate(), ['status' => 'succeeded']));
    }

    private function paymentCreate(?array $requireFreshCvc = null): PaymentCreate
    {
        $address = $this->createMock(Address::class);
        $address->method('getStreet')->willReturn(['123 Main St']);
        $logger = $this->createMock(Logger::class);
        $logger->method('withName')->willReturn($logger);

        return new PaymentCreate(
            $this->createMock(ClientFactory::class),
            $this->createMock(Config::class),
            $logger,
            10.0,
            'card_saved123',
            true,
            '555-555-0100',
            'shopper@example.com',
            $address,
            $address,
            null,
            '100000001',
            null,
            $requireFreshCvc
        );
    }

    private function requestData(PaymentCreate $request): array
    {
        return (new \ReflectionProperty($request, 'requestData'))->getValue($request);
    }

    /**
     * Runs validateResponse as if PublicSquare had answered with $body, without an HTTP call.
     */
    private function validate(PaymentCreate $request, array $body): bool
    {
        (new \ReflectionProperty($request, 'response'))->setValue($request, new \Laminas\Http\Response());
        (new \ReflectionProperty($request, 'responseData'))->setValue($request, $body);
        return (new \ReflectionMethod($request, 'validateResponse'))->invoke($request, $body);
    }
}
