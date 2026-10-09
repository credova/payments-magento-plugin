<?php

namespace PublicSquare\Payments\Test\Unit\Model\CvvRecollection;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Model\Quote;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Model\CvvRecollection\CvvRequestStore;
use PublicSquare\Payments\Model\CvvRecollection\CvvRequirement;
use PublicSquare\Payments\Model\CvvRecollection\RequirementResolver;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddress;

class CvvRequirementTest extends TestCase
{
    private PaymentTokenManagementInterface&MockObject $tokenManagement;
    private CartRepositoryInterface&MockObject $cartRepository;
    private RequirementResolver&MockObject $resolver;
    private CvvRequestStore&MockObject $requestStore;
    private CvvRequirement $service;

    protected function setUp(): void
    {
        $this->tokenManagement = $this->createMock(PaymentTokenManagementInterface::class);
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->resolver = $this->createMock(RequirementResolver::class);
        $this->requestStore = $this->createMock(CvvRequestStore::class);
        $logger = $this->createMock(Logger::class);
        $logger->method('withName')->willReturn($logger);

        $this->service = new CvvRequirement(
            $this->tokenManagement,
            $this->cartRepository,
            $this->resolver,
            $this->requestStore,
            $logger
        );
    }

    public function testThrowsWhenTheSavedCardIsNotTheCustomers(): void
    {
        $this->tokenManagement->method('getByPublicHash')->with('hash', 7)->willReturn(null);

        $this->expectException(NoSuchEntityException::class);
        $this->service->check(7, 'hash');
    }

    public function testThrowsWhenTheSavedCardIsInactive(): void
    {
        $this->tokenManagement->method('getByPublicHash')->willReturn($this->token(false));

        $this->expectException(NoSuchEntityException::class);
        $this->service->check(7, 'hash');
    }

    public function testNotRequiredForOtherPaymentMethodsCards(): void
    {
        $this->tokenManagement->method('getByPublicHash')->willReturn($this->token());
        $this->resolver->method('isPublicSquareCard')->willReturn(false);
        $this->requestStore->expects($this->never())->method('stamp');

        $result = $this->service->check(7, 'hash');

        $this->assertFalse($result->getRequired());
        $this->assertNull($result->getCardId());
    }

    public function testNotRequiredReturnsNoCardIdAndDoesNotStamp(): void
    {
        $this->givenCartWithShippingAddress('123 Main St');
        $this->resolver->method('isRequired')->willReturn(false);
        $this->requestStore->expects($this->never())->method('stamp');

        $result = $this->service->check(7, 'hash');

        $this->assertFalse($result->getRequired());
        $this->assertNull($result->getCardId());
        $this->assertNull($result->getCardBrand());
    }

    public function testRequiredStampsTheCartAndReturnsTheCard(): void
    {
        $this->givenCartWithShippingAddress('1 New St');
        $this->resolver->method('isRequired')->willReturn(true);
        $this->requestStore->expects($this->once())->method('stamp')->with(42, 3);

        $result = $this->service->check(7, 'hash');

        $this->assertTrue($result->getRequired());
        $this->assertSame('card_saved123', $result->getCardId());
        $this->assertSame('visa', $result->getCardBrand());
    }

    public function testComparesTheCartsShippingAddressForTheCartsStore(): void
    {
        $this->givenCartWithShippingAddress('1 New St');
        $this->resolver->expects($this->once())
            ->method('isRequired')
            ->with(
                7,
                $this->anything(),
                $this->callback(fn (ShippingAddress $a) => str_starts_with($a->getFingerprint(), '1 new st|')),
                2
            )
            ->willReturn(false);

        $this->service->check(7, 'hash');
    }

    public function testVirtualCartsHaveNoShippingAddressToCompare(): void
    {
        $this->tokenManagement->method('getByPublicHash')->willReturn($this->token());
        $this->resolver->method('isPublicSquareCard')->willReturn(true);
        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(true);
        $this->cartRepository->method('getActiveForCustomer')->willReturn($quote);
        $this->resolver->expects($this->once())
            ->method('isRequired')
            ->with(7, $this->anything(), null, $this->anything())
            ->willReturn(false);

        $this->assertFalse($this->service->check(7, 'hash')->getRequired());
    }

    private function givenCartWithShippingAddress(string $street): void
    {
        $this->tokenManagement->method('getByPublicHash')->willReturn($this->token());
        $this->resolver->method('isPublicSquareCard')->willReturn(true);

        $address = $this->createMock(AddressInterface::class);
        $address->method('getStreet')->willReturn([$street]);
        $address->method('getCity')->willReturn('Newark');
        $address->method('getRegionId')->willReturn(15);
        $address->method('getPostcode')->willReturn('19711');
        $address->method('getCountryId')->willReturn('US');

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(42);
        $quote->method('getStoreId')->willReturn(2);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($address);
        $this->cartRepository->method('getActiveForCustomer')->with(7)->willReturn($quote);
    }

    private function token(bool $active = true): PaymentTokenInterface
    {
        $token = $this->createMock(PaymentTokenInterface::class);
        $token->method('getIsActive')->willReturn($active);
        $token->method('getEntityId')->willReturn(3);
        $token->method('getGatewayToken')->willReturn('card_saved123');
        $token->method('getTokenDetails')->willReturn('{"type":"visa","maskedCC":"4242"}');
        return $token;
    }
}
