<?php

namespace PublicSquare\Payments\Test\Unit\Gateway\Command;

use Magento\Payment\Gateway\Command\CommandException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Gateway\Command\CaptureCommand;
use PublicSquare\Payments\Gateway\PaymentExecutor;
use PublicSquare\Payments\Logger\Logger;

/**
 * CaptureCommand runs for "capture" and "vault_sale". It finds the PSQ card id for the payment, then captures an
 * existing authorization or authorizes and captures in one step.
 */
class CaptureCommandTest extends TestCase
{
    private PaymentExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = $this->createMock(PaymentExecutor::class);
        // The real method throws, so a failed lookup stops the command.
        $this->executor->method('throwUserFriendlyException')
            ->willReturnCallback(fn (\Exception $e) => throw new CommandException(__('%1', $e->getMessage())));
    }

    public function testChargesTheAdminOrderCardFromTheNonce(): void
    {
        $payment = new CapturePayment(['payment_method_nonce' => 'card_1']);
        $this->executor->expects($this->once())->method('executeAuthorizeCapture');

        $this->command()->execute($this->subject($payment));

        $this->assertSame('card_1', $payment->getAdditionalInformation('cardId'));
    }

    public function testChargesASavedCardOfTheCustomer(): void
    {
        $payment = new CapturePayment(['public_hash' => 'hash_1'], customerId: 42);
        $this->executor->method('getCardIdFromPublicHash')->with('hash_1', 42)->willReturn('card_9');
        $this->executor->expects($this->once())->method('executeAuthorizeCapture');

        $this->command()->execute($this->subject($payment));

        $this->assertSame('card_9', $payment->getAdditionalInformation('cardId'));
    }

    public static function unusableSavedCards(): array
    {
        return ['a guest order' => [null, 'card_9', 'Customer not found'], 'an unknown card' => [42, '', 'Card not found']];
    }

    #[DataProvider('unusableSavedCards')]
    public function testRefusesASavedCardFor(?int $customerId, string $cardId, string $message): void
    {
        $payment = new CapturePayment(['public_hash' => 'hash_1'], customerId: $customerId);
        $this->executor->method('getCardIdFromPublicHash')->willReturn($cardId);
        $this->executor->expects($this->never())->method('executeAuthorizeCapture');

        $this->expectException(CommandException::class);
        $this->expectExceptionMessage($message);
        $this->command()->execute($this->subject($payment));
    }

    public function testCapturesAnExistingAuthorization(): void
    {
        $this->executor->method('getTransaction')->willReturn(new \stdClass());
        $this->executor->expects($this->once())->method('executeCapture');
        $this->executor->expects($this->never())->method('executeAuthorizeCapture');

        $this->command()->execute($this->subject(new CapturePayment(['cardId' => 'card_1'])));
    }

    public function testChargesNothingForAZeroAmount(): void
    {
        $this->executor->expects($this->never())->method('executeCapture');
        $this->executor->expects($this->never())->method('executeAuthorizeCapture');

        $this->command()->execute($this->subject(new CapturePayment(['cardId' => 'card_1']), 0));
    }

    private function command(): CaptureCommand
    {
        return new CaptureCommand(new Logger(), $this->executor);
    }

    private function subject(CapturePayment $payment, float $amount = 19.99): array
    {
        $paymentDO = new class($payment) {
            public function __construct(private CapturePayment $payment) {}
            public function getPayment() { return $this->payment; }
        };
        return ['payment' => $paymentDO, 'amount' => $amount];
    }
}

/** The order payment fields CaptureCommand reads and writes. */
class CapturePayment
{
    public function __construct(private array $additionalInformation, private ?int $customerId = null) {}

    public function getAdditionalInformation($key) { return $this->additionalInformation[$key] ?? null; }
    public function setAdditionalInformation($key, $value) { $this->additionalInformation[$key] = $value; }

    public function getOrder()
    {
        return new class($this->customerId) {
            public function __construct(private ?int $customerId) {}
            public function getCustomerId() { return $this->customerId; }
        };
    }
}
