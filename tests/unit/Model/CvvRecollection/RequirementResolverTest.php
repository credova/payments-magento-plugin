<?php

namespace PublicSquare\Payments\Test\Unit\Model\CvvRecollection;

use Magento\Vault\Api\Data\PaymentTokenInterface;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Helper\Config;
use PublicSquare\Payments\Model\CvvRecollection\RequirementResolver;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddress;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddressHistory;

class RequirementResolverTest extends TestCase
{
    public function testNotRequiredWhenTheSettingIsOff(): void
    {
        $history = $this->createMock(ShippingAddressHistory::class);
        $history->expects($this->never())->method('getShippingAddressesForCard');

        $resolver = new RequirementResolver($this->config(false), $history);

        $this->assertFalse($resolver->isRequired(7, $this->token(), $this->address('1 New St')));
    }

    public function testNotRequiredWithoutAShippingAddress(): void
    {
        $resolver = new RequirementResolver($this->config(true), $this->history([]));

        $this->assertFalse($resolver->isRequired(7, $this->token(), null));
    }

    public function testNotRequiredForCardsFromOtherPaymentMethods(): void
    {
        $resolver = new RequirementResolver($this->config(true), $this->history([]));

        $this->assertFalse($resolver->isRequired(7, $this->token('braintree'), $this->address('1 New St')));
        $this->assertFalse($resolver->isRequired(7, $this->token('publicsquare_payments', 'tok_123'), $this->address('1 New St')));
    }

    public function testRequiredWhenTheCardHasNoOrders(): void
    {
        $resolver = new RequirementResolver($this->config(true), $this->history([]));

        $this->assertTrue($resolver->isRequired(7, $this->token(), $this->address('1 New St')));
    }

    public function testRequiredWhenTheCardHasNeverShippedToTheAddress(): void
    {
        $resolver = new RequirementResolver(
            $this->config(true),
            $this->history([$this->address('123 Main St'), $this->address('456 Oak Ave')])
        );

        $this->assertTrue($resolver->isRequired(7, $this->token(), $this->address('1 New St')));
    }

    public function testNotRequiredWhenTheCardHasShippedToTheAddressBefore(): void
    {
        $resolver = new RequirementResolver(
            $this->config(true),
            $this->history([$this->address('123 Main St'), $this->address('456 Oak Ave')])
        );

        $this->assertFalse($resolver->isRequired(7, $this->token(), $this->address('456 OAK AVE.')));
    }

    public function testLooksUpHistoryForTheCustomerAndCard(): void
    {
        $history = $this->createMock(ShippingAddressHistory::class);
        $history->expects($this->once())
            ->method('getShippingAddressesForCard')
            ->with(7, 'card_saved123')
            ->willReturn([]);

        (new RequirementResolver($this->config(true), $history))
            ->isRequired(7, $this->token(), $this->address('1 New St'));
    }

    private function config(bool $enabled): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('isCvvRecollectionEnabled')->willReturn($enabled);
        return $config;
    }

    private function history(array $addresses): ShippingAddressHistory
    {
        $history = $this->createMock(ShippingAddressHistory::class);
        $history->method('getShippingAddressesForCard')->willReturn($addresses);
        return $history;
    }

    private function token(string $method = 'publicsquare_payments', string $gatewayToken = 'card_saved123'): PaymentTokenInterface
    {
        $token = $this->createMock(PaymentTokenInterface::class);
        $token->method('getPaymentMethodCode')->willReturn($method);
        $token->method('getGatewayToken')->willReturn($gatewayToken);
        return $token;
    }

    private function address(string $street): ShippingAddress
    {
        return new ShippingAddress([$street], 'Newark', 15, 'Delaware', '19711', 'US');
    }
}
