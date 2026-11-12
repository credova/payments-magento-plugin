<?php

namespace PublicSquare\Payments\Test\Unit\Gateway\Command;

use Magento\Payment\Gateway\Command\CommandException;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Gateway\Command\CaptureCommand;
use PublicSquare\Payments\Gateway\PaymentExecutor;
use PublicSquare\Payments\Logger\Logger;

/**
 * CaptureCommand runs for "capture" and "vault_sale". It captures an existing authorization, or authorizes and
 * captures in one step (PaymentExecutor picks the card to charge).
 */
class CaptureCommandTest extends TestCase
{
    private PaymentExecutor $executor;

    protected function setUp(): void
    {
        $this->executor = $this->createMock(PaymentExecutor::class);
        // The real method throws, so a refusal stops the command.
        $this->executor->method('throwUserFriendlyException')
            ->willReturnCallback(fn (\Exception $e) => throw new CommandException(__('%1', $e->getMessage())));
    }

    public function testAuthorizesAndCapturesANewPayment(): void
    {
        $this->executor->expects($this->once())->method('executeAuthorizeCapture');
        $this->executor->expects($this->never())->method('executeCapture');

        $this->command()->execute($this->subject(new CapturePayment()));
    }

    public function testCapturesAnExistingAuthorization(): void
    {
        $this->executor->method('getTransaction')->willReturn(new \stdClass());
        $this->executor->expects($this->once())->method('executeCapture');
        $this->executor->expects($this->never())->method('executeAuthorizeCapture');

        $this->command()->execute($this->subject(new CapturePayment()));
    }

    /**
     * A second partial invoice: the first capture turned the authorization into the capture transaction, so there is
     * no authorization left. Creating a new payment would charge the card again.
     */
    public function testRefusesToChargeAgainAfterTheAuthorizationIsCaptured(): void
    {
        $this->executor->method('getTransaction')->willReturn(null);
        $this->executor->expects($this->never())->method('executeAuthorizeCapture');

        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('This payment was already captured or canceled.');
        $this->command()->execute($this->subject(new CapturePayment('pmt_1')));
    }

    public function testChargesNothingForAZeroAmount(): void
    {
        $this->executor->expects($this->never())->method('executeCapture');
        $this->executor->expects($this->never())->method('executeAuthorizeCapture');

        $this->command()->execute($this->subject(new CapturePayment(), 0));
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

/** The order payment field CaptureCommand reads: PublicSquare's payment id, once the payment was sent. */
class CapturePayment
{
    public function __construct(private ?string $lastTransId = null) {}

    public function getLastTransId() { return $this->lastTransId; }
}
