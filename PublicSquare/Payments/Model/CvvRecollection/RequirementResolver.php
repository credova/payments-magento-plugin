<?php

namespace PublicSquare\Payments\Model\CvvRecollection;

use Magento\Store\Model\ScopeInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use PublicSquare\Payments\Helper\Config;

/**
 * Decides whether checkout should ask for a saved card's CVV again, mirroring PublicSquare's card security code
 * rule: the setting is on, and the card has an earlier successful payment but none that shipped to this address.
 *
 * A card with no successful payments isn't asked: its CVV was collected when the card was entered.
 */
class RequirementResolver
{
    private Config $config;
    private ShippingAddressHistory $history;

    public function __construct(Config $config, ShippingAddressHistory $history)
    {
        $this->config = $config;
        $this->history = $history;
    }

    /**
     * @param int|string|null $storeId Store whose setting applies; null for the current store
     */
    public function isRequired(
        int $customerId,
        PaymentTokenInterface $token,
        ?ShippingAddress $shippingAddress,
        $storeId = null,
    ): bool {
        if (!$this->config->isCvvRecollectionEnabled(ScopeInterface::SCOPE_STORE, $storeId)) {
            return false;
        }
        // Virtual-only carts have nothing to compare.
        if ($shippingAddress === null) {
            return false;
        }
        if (!$this->isPublicSquareCard($token)) {
            return false;
        }

        $history = $this->history->getCardPaymentHistory($customerId, $token->getGatewayToken());
        if ($history->successfulPayments === 0) {
            return false;
        }
        foreach ($history->shippingAddresses as $previous) {
            if ($previous->isSameAddressAs($shippingAddress)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a saved card belongs to this payment method.
     *
     * Magento's vault holds saved cards for every payment method in the store, and a lookup by public
     * hash doesn't check which one. The plugin sets this code on every card it saves.
     */
    public function isPublicSquareCard(PaymentTokenInterface $token): bool
    {
        return $token->getPaymentMethodCode() === Config::CODE;
    }
}
