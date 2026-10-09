<?php

namespace PublicSquare\Payments\Test\Unit\Model\CvvRecollection;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\State;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Exception\CvvRecollectionRequiredException;
use PublicSquare\Payments\Helper\Config;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Model\CvvRecollection\CvvRequestStore;
use PublicSquare\Payments\Model\CvvRecollection\PaymentGuard;
use PublicSquare\Payments\Model\CvvRecollection\RequirementResolver;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddress;

class PaymentGuardTest extends TestCase
{
    private UserContextInterface&MockObject $userContext;
    private State&MockObject $appState;
    private RequirementResolver&MockObject $resolver;
    private CvvRequestStore&MockObject $requestStore;
    private Config&MockObject $config;
    private PaymentGuard $guard;

    protected function setUp(): void
    {
        $this->userContext = $this->createMock(UserContextInterface::class);
        $this->userContext->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_CUSTOMER);
        $this->appState = $this->createMock(State::class);
        $this->appState->method('getAreaCode')->willReturn('webapi_rest');

        $token = $this->createMock(PaymentTokenInterface::class);
        $token->method('getEntityId')->willReturn(3);
        $tokenManagement = $this->createMock(PaymentTokenManagementInterface::class);
        $tokenManagement->method('getByPublicHash')->with('hash', 7)->willReturn($token);

        $this->resolver = $this->createMock(RequirementResolver::class);
        $this->requestStore = $this->createMock(CvvRequestStore::class);
        $this->config = $this->createMock(Config::class);
        $this->config->method('getFreshCvcMaxAgeSeconds')->willReturn(1800);
        $logger = $this->createMock(Logger::class);
        $logger->method('withName')->willReturn($logger);

        $this->guard = new PaymentGuard(
            $this->userContext,
            $this->appState,
            $tokenManagement,
            $this->resolver,
            $this->requestStore,
            $this->config,
            $logger
        );
    }

    public function testNotRequiredLeavesThePaymentAlone(): void
    {
        $this->resolver->method('isRequired')->willReturn(false);
        $this->requestStore->expects($this->never())->method('getRequestedAt');
        $payment = new FakeOrderPayment();

        $this->assertNull($this->guard->check($payment, 'hash'));
        $this->assertSame([], $payment->additionalInformation);
    }

    public function testRejectsWhenCheckoutNeverAskedForTheCvv(): void
    {
        $this->resolver->method('isRequired')->willReturn(true);
        $this->requestStore->method('getRequestedAt')->with(42, 3)->willReturn(null);

        $this->expectException(CvvRecollectionRequiredException::class);
        $this->expectExceptionMessage("Please re-enter your card's security code to continue.");
        $this->guard->check(new FakeOrderPayment(), 'hash');
    }

    public function testRecordsTheStampWithoutSendingItWhenTheFreshCvcCheckIsOff(): void
    {
        $this->resolver->method('isRequired')->willReturn(true);
        $this->requestStore->method('getRequestedAt')->willReturn($this->stamp());
        $this->config->method('isFreshCvcCheckEnabled')->willReturn(false);
        $payment = new FakeOrderPayment();

        $this->assertNull($this->guard->check($payment, 'hash'));
        $this->assertSame(
            ['cvvRecollectionRequired' => true, 'cvvRequestedAt' => '2026-10-08T22:42:10+00:00'],
            $payment->additionalInformation
        );
    }

    public function testReturnsRequireFreshCvcWhenTheFreshCvcCheckIsOn(): void
    {
        $this->resolver->method('isRequired')->willReturn(true);
        $this->requestStore->method('getRequestedAt')->willReturn($this->stamp());
        $this->config->method('isFreshCvcCheckEnabled')->willReturn(true);

        $this->assertSame(
            ['updated_after' => '2026-10-08T22:42:10Z', 'max_age_seconds' => 1800],
            $this->guard->check(new FakeOrderPayment(), 'hash')
        );
    }

    public function testComparesTheOrdersShippingAddress(): void
    {
        $this->resolver->expects($this->once())
            ->method('isRequired')
            ->with(
                7,
                $this->anything(),
                $this->callback(fn (ShippingAddress $a) => str_starts_with($a->getFingerprint(), '1 new st|')),
                1
            )
            ->willReturn(false);

        $this->guard->check(new FakeOrderPayment(), 'hash');
    }

    public function testVirtualOrdersHaveNoShippingAddressToCompare(): void
    {
        $this->resolver->expects($this->once())
            ->method('isRequired')
            ->with(7, $this->anything(), null, $this->anything())
            ->willReturn(false);
        $payment = new FakeOrderPayment();
        $payment->order->isVirtual = true;

        $this->guard->check($payment, 'hash');
    }

    public function testGuestOrdersAreSkipped(): void
    {
        $this->resolver->expects($this->never())->method('isRequired');
        $payment = new FakeOrderPayment();
        $payment->order->customerId = null;

        $this->assertNull($this->guard->check($payment, 'hash'));
    }

    public static function ordersWithoutAShopper(): array
    {
        return [
            'admin user' => [UserContextInterface::USER_TYPE_ADMIN, 'adminhtml'],
            'integration token' => [UserContextInterface::USER_TYPE_INTEGRATION, 'webapi_rest'],
            'cron or CLI (no user)' => [null, 'crontab'],
            'customer user in the admin area' => [UserContextInterface::USER_TYPE_CUSTOMER, 'adminhtml'],
        ];
    }

    #[DataProvider('ordersWithoutAShopper')]
    public function testOrdersNotPlacedByAShopperAreSkipped(?int $userType, string $area): void
    {
        $userContext = $this->createMock(UserContextInterface::class);
        $userContext->method('getUserType')->willReturn($userType);
        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willReturn($area);
        $resolver = $this->createMock(RequirementResolver::class);
        $resolver->expects($this->never())->method('isRequired');
        $logger = $this->createMock(Logger::class);
        $logger->method('withName')->willReturn($logger);

        $guard = new PaymentGuard(
            $userContext,
            $appState,
            $this->createMock(PaymentTokenManagementInterface::class),
            $resolver,
            $this->requestStore,
            $this->config,
            $logger
        );

        $this->assertNull($guard->check(new FakeOrderPayment(), 'hash'));
    }

    private function stamp(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-08 22:42:10', new \DateTimeZone('UTC'));
    }
}

/**
 * The parts of an order the guard reads, with a new shipping address by default.
 */
class FakeOrder
{
    public ?int $customerId = 7;
    public bool $isVirtual = false;

    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    public function getStoreId(): int
    {
        return 1;
    }

    public function getQuoteId(): int
    {
        return 42;
    }

    public function getIsVirtual(): bool
    {
        return $this->isVirtual;
    }

    public function getShippingAddress(): OrderAddressInterface
    {
        return new class implements OrderAddressInterface {
            public function getStreet() { return ['1 New St']; }
            public function getCity() { return 'Newark'; }
            public function getRegionId() { return 15; }
            public function getRegion() { return 'Delaware'; }
            public function getPostcode() { return '19711'; }
            public function getCountryId() { return 'US'; }
        };
    }
}

class FakeOrderPayment
{
    public FakeOrder $order;
    public array $additionalInformation = [];

    public function __construct()
    {
        $this->order = new FakeOrder();
    }

    public function getOrder(): FakeOrder
    {
        return $this->order;
    }

    public function setAdditionalInformation($key, $value = null): void
    {
        $this->additionalInformation[$key] = $value;
    }
}
