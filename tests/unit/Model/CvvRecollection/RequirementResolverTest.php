<?php

namespace PublicSquare\Payments\Test\Unit\Model\CvvRecollection;

use Magento\Vault\Api\Data\PaymentTokenInterface;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Helper\Config;
use PublicSquare\Payments\Model\CvvRecollection\CardPaymentHistory;
use PublicSquare\Payments\Model\CvvRecollection\RequirementResolver;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddress;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddressHistory;

class RequirementResolverTest extends TestCase
{
    public function testNotRequiredWhenTheSettingIsOff(): void
    {
        $history = $this->createMock(ShippingAddressHistory::class);
        $history->expects($this->never())->method('getCardPaymentHistory');

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
    }

    public function testIdentifiesPublicSquareCardsByPaymentMethodOnly(): void
    {
        $resolver = new RequirementResolver($this->config(true), $this->history([]));

        // A card ID in a format we haven't seen is still ours, so the rule still applies.
        $this->assertTrue($resolver->isPublicSquareCard($this->token('publicsquare_payments', 'pm_new_format_123')));
        $this->assertFalse($resolver->isPublicSquareCard($this->token('braintree', 'card_lookalike')));
    }

    /** Its CVV was collected when the card was entered, and PublicSquare's rule needs an earlier payment too. */
    public function testNotRequiredWhenTheCardHasNoSuccessfulPayments(): void
    {
        $resolver = new RequirementResolver($this->config(true), $this->history([], 0));

        $this->assertFalse($resolver->isRequired(7, $this->token(), $this->address('1 New St')));
    }

    /** A card used only for virtual orders has payments but no addresses, so any address is new. */
    public function testRequiredWhenTheCardHasPaymentsButNoShippedAddresses(): void
    {
        $resolver = new RequirementResolver($this->config(true), $this->history([], 2));

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

        $this->assertFalse($resolver->isRequired(7, $this->token(), $this->address(' 456 OAK  AVE')));
    }

    public function testLooksUpHistoryForTheCustomerAndCard(): void
    {
        $history = $this->createMock(ShippingAddressHistory::class);
        $history->expects($this->once())
            ->method('getCardPaymentHistory')
            ->with(7, 'card_saved123')
            ->willReturn(new CardPaymentHistory(0, []));

        (new RequirementResolver($this->config(true), $history))
            ->isRequired(7, $this->token(), $this->address('1 New St'));
    }

    private function config(bool $enabled): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('isCvvRecollectionEnabled')->willReturn($enabled);
        return $config;
    }

    /** Defaults to one successful payment per address. */
    private function history(array $addresses, ?int $successfulPayments = null): ShippingAddressHistory
    {
        $history = $this->createMock(ShippingAddressHistory::class);
        $history->method('getCardPaymentHistory')
            ->willReturn(new CardPaymentHistory($successfulPayments ?? count($addresses), $addresses));
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
        return new ShippingAddress($street, '19711', 'US');
    }
}
