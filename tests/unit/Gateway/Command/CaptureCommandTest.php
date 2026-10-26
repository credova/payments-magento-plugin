<?php

namespace PublicSquare\Payments\Test\Unit\Gateway\Command;

use Magento\Payment\Gateway\Command\CommandException;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Gateway\Command\CaptureCommand;
use PublicSquare\Payments\Gateway\PaymentExecutor;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Model\CardResolver;

/**
 * CaptureCommand runs for "capture" and "vault_sale". It finds the PSQ card id for the payment, then captures an
 * existing authorization or authorizes and captures in one step. CardResolverTest covers finding the card.
 */
class CaptureCommandTest extends TestCase
{
    private PaymentExecutor $executor;
    private CardResolver $cardResolver;

    protected function setUp(): void
    {
        $this->executor = $this->createMock(PaymentExecutor::class);
        $this->cardResolver = $this->createMock(CardResolver::class);
        // The real method throws, so a failed lookup stops the command.
        $this->executor->method('throwUserFriendlyException')
            ->willReturnCallback(fn (\Exception $e) => throw new CommandException(__('%1', $e->getMessage())));
    }

    public function testChargesTheCardOfThePayment(): void
    {
        $payment = new CapturePayment(['public_hash' => 'hash_1'], customerId: 42);
        $this->cardResolver->method('getCardId')->with($payment, 42)->willReturn('card_9');
        $this->executor->expects($this->once())->method('executeAuthorizeCapture');

        $this->command()->execute($this->subject($payment));

        $this->assertSame('card_9', $payment->getAdditionalInformation('cardId'));
    }

    public function testRefusesASavedCardItCannotUse(): void
    {
        $payment = new CapturePayment(['public_hash' => 'hash_1'], customerId: 42);
        $this->cardResolver->method('getCardId')->willReturn(null);
        $this->executor->expects($this->never())->method('executeAuthorizeCapture');

        $this->expectException(CommandException::class);
        $this->command()->execute($this->subject($payment));
    }

    public function testCapturesAnExistingAuthorization(): void
    {
        $this->executor->method('getTransaction')->willReturn(new \stdClass());
        $this->executor->expects($this->once())->method('executeCapture');
        $this->executor->expects($this->never())->method('executeAuthorizeCapture');

        $this->command()->execute($this->subject(new CapturePayment(['cardId' => 'card_1'])));
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
        $this->command()->execute($this->subject(new CapturePayment(['cardId' => 'card_1'], lastTransId: 'pmt_1')));
    }

    public function testChargesNothingForAZeroAmount(): void
    {
        $this->executor->expects($this->never())->method('executeCapture');
        $this->executor->expects($this->never())->method('executeAuthorizeCapture');

        $this->command()->execute($this->subject(new CapturePayment(['cardId' => 'card_1']), 0));
    }

    private function command(): CaptureCommand
    {
        return new CaptureCommand(new Logger(), $this->executor, $this->cardResolver);
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
    public function __construct(
        private array $additionalInformation,
        private ?int $customerId = null,
        private ?string $lastTransId = null,
    ) {}

    public function getLastTransId() { return $this->lastTransId; }

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
