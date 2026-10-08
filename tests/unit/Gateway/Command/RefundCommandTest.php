<?php

namespace PublicSquare\Payments\Test\Unit\Gateway\Command;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Api\Authenticated\PaymentRefund;
use PublicSquare\Payments\Api\Authenticated\PaymentRefundFactory;
use PublicSquare\Payments\Api\Constants;
use PublicSquare\Payments\Gateway\Command\RefundCommand;
use PublicSquare\Payments\Logger\Logger;

class RefundCommandTest extends TestCase
{
    // PublicSquare IDs start with a type prefix: pmt_ for a payment, rfd_ for a refund.
    private const PAYMENT_ID = 'pmt_payment';
    private const REFUND_ID = 'rfd_refund';
    private const CHARGE_ID_FROM_ANOTHER_GATEWAY = 'ch_charge';

    private PaymentRefundFactory $paymentRefundFactory;
    private RefundCommand $command;

    protected function setUp(): void
    {
        $this->paymentRefundFactory = $this->createMock(PaymentRefundFactory::class);
        $this->command = new RefundCommand(
            $this->paymentRefundFactory,
            $this->createMock(TransactionRepositoryInterface::class),
            new Logger(),
        );
    }

    public static function amounts(): array
    {
        return [
            '19.99' => [19.99, 1999],
            '4.35' => [4.35, 435],
            '1.15' => [1.15, 115],
            '0.29' => [0.29, 29],
        ];
    }

    #[DataProvider('amounts')]
    public function testSendsTheAmountInWholeCents(float $amount, int $cents): void
    {
        $this->paymentRefundFactory->expects($this->once())
            ->method('create')
            ->with($this->callback(fn (array $data) => $data['amount'] === (float) $cents))
            ->willReturn($this->refundResponse(['id' => self::REFUND_ID]));

        $this->command->execute($this->commandSubject(new FakePayment(self::PAYMENT_ID), $amount));
    }

    public function testSavesTheRefundIdOnThePayment(): void
    {
        $payment = new FakePayment(self::PAYMENT_ID);
        $this->paymentRefundFactory->method('create')->willReturn($this->refundResponse(['id' => self::REFUND_ID]));

        $this->command->execute($this->commandSubject($payment, 10.00));

        $this->assertSame(self::REFUND_ID, $payment->getAdditionalInformation()[Constants::REFUND_ID_KEY]);
    }

    public function testRefundsThePaymentIdFromASuffixedTransactionId(): void
    {
        $this->assertSame(
            self::PAYMENT_ID,
            $this->command->getTransactionId(new FakePayment(self::PAYMENT_ID . '-capture')),
        );
    }

    public function testRejectsAnOnlineRefundForANonPublicSquareTransaction(): void
    {
        $this->paymentRefundFactory->expects($this->never())->method('create');

        $this->expectException(CommandException::class);
        $this->expectExceptionMessage('The payment can only be refunded via the PublicSquare Dashboard.');

        $this->command->execute($this->commandSubject(new FakePayment(self::CHARGE_ID_FROM_ANOTHER_GATEWAY), 10.00));
    }

    private function refundResponse(array $data): PaymentRefund
    {
        $refund = $this->createMock(PaymentRefund::class);
        $refund->method('getResponseData')->willReturn($data);
        return $refund;
    }

    private function commandSubject(FakePayment $payment, float $amount): array
    {
        return [
            'payment' => new class ($payment) {
                public function __construct(private FakePayment $payment)
                {
                }

                public function getPayment(): FakePayment
                {
                    return $this->payment;
                }
            },
            'amount' => $amount,
        ];
    }
}

// The order payment API is mostly magic getters, so a fake reads more clearly than a mock.
class FakePayment implements InfoInterface
{
    private array $additionalInformation = [];

    public function __construct(private string $lastTransId)
    {
    }

    public function getAdditionalInformation($key = null)
    {
        return $key === null ? $this->additionalInformation : ($this->additionalInformation[$key] ?? null);
    }

    public function setAdditionalInformation($key, $value = null)
    {
        $this->additionalInformation = is_array($key) ? $key : [$key => $value] + $this->additionalInformation;
        return $this;
    }

    public function getLastTransId(): string
    {
        return $this->lastTransId;
    }

    public function getRefundTransactionId(): ?string
    {
        return null;
    }

    public function getCreditmemo(): null
    {
        return null;
    }

    public function getOrder(): object
    {
        return new class {
            public function getIncrementId(): string
            {
                return '000000001';
            }

            public function getId(): int
            {
                return 1;
            }
        };
    }
}
