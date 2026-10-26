<?php

namespace PublicSquare\Payments\Test\Unit\Model;

use Magento\Quote\Model\Quote\Payment;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Helper\Config;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Model\CardResolver;
use PublicSquare\Payments\Model\CvvRecollection\RequirementResolver;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddressHistory;

class CardResolverTest extends TestCase
{
    public static function payments(): array
    {
        return [
            'a card entered in the admin' => [['payment_method_nonce' => 'card_admin', 'cardId' => 'card_old'], 'card_admin'],
            'a saved card' => [['public_hash' => 'hash_active', 'cardId' => 'card_old'], 'card_saved'],
            'a card entered at checkout' => [['cardId' => 'card_checkout'], 'card_checkout'],
            'no card' => [[], null],
        ];
    }

    #[DataProvider('payments')]
    public function testFindsTheCardOf(array $additionalInformation, ?string $cardId): void
    {
        $payment = (new Payment())->setAdditionalInformation($additionalInformation);

        $this->assertSame($cardId, $this->resolver()->getCardId($payment, 42));
    }

    public static function unusableSavedCards(): array
    {
        return [
            'a guest' => ['hash_active', null],
            'another customer' => ['hash_active', 7],
            'an unknown hash' => ['hash_unknown', 42],
            'a card the customer deleted' => ['hash_deleted', 42],
            "another payment method's card" => ['hash_braintree', 42],
        ];
    }

    #[DataProvider('unusableSavedCards')]
    public function testFindsNoSavedCardFor(string $publicHash, ?int $customerId): void
    {
        $this->assertNull($this->resolver()->getSavedCardId($publicHash, $customerId));
    }

    public function testFindsNoCardForAnUnusableSavedCardEvenWithACardId(): void
    {
        $payment = (new Payment())->setAdditionalInformation(['public_hash' => 'hash_deleted', 'cardId' => 'card_old']);

        $this->assertNull($this->resolver()->getCardId($payment, 42));
    }

    /** Customer 42 saved hash_active, deleted hash_deleted, and saved hash_braintree with another payment method. */
    private function resolver(): CardResolver
    {
        $tokens = [
            'hash_active' => $this->token('card_saved'),
            'hash_deleted' => $this->token('card_deleted', false),
            'hash_braintree' => $this->token('card_braintree', true, 'braintree'),
        ];
        $tokenManagement = $this->createMock(PaymentTokenManagementInterface::class);
        $tokenManagement->method('getByPublicHash')
            ->willReturnCallback(fn ($hash, $customerId) => $customerId === 42 ? ($tokens[$hash] ?? null) : null);
        $requirementResolver = new RequirementResolver(
            $this->createMock(Config::class),
            $this->createMock(ShippingAddressHistory::class),
        );
        return new CardResolver($tokenManagement, $requirementResolver, new Logger());
    }

    private function token(string $cardId, bool $active = true, string $method = Config::CODE): PaymentTokenInterface
    {
        $token = $this->createMock(PaymentTokenInterface::class);
        $token->method('getGatewayToken')->willReturn($cardId);
        $token->method('getIsActive')->willReturn($active);
        $token->method('getPaymentMethodCode')->willReturn($method);
        return $token;
    }
}
