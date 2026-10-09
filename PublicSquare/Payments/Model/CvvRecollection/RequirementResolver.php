<?php

namespace PublicSquare\Payments\Model\CvvRecollection;

use Magento\Store\Model\ScopeInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use PublicSquare\Payments\Helper\Config;

/**
 * Decides whether a saved-card payment needs the card's CVV re-entered.
 *
 * Required when the setting is on and the saved card has never shipped to this address on one of the
 * customer's orders. A card with no orders (for example, one added from My Account) is always required.
 * The checkout endpoint and the payment guard both use this, so they always agree.
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

        foreach ($this->history->getShippingAddressesForCard($customerId, $token->getGatewayToken()) as $previous) {
            if ($previous->isSamePlaceAs($shippingAddress)) {
                return false;
            }
        }
        return true;
    }

    public function isPublicSquareCard(PaymentTokenInterface $token): bool
    {
        return $token->getPaymentMethodCode() === Config::CODE
            && str_starts_with($token->getGatewayToken(), 'card_');
    }
}
