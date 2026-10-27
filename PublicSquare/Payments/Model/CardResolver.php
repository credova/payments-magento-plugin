<?php

namespace PublicSquare\Payments\Model;

use Magento\Payment\Model\InfoInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PublicSquare\Payments\Helper\Config;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Model\CvvRecollection\RequirementResolver;

/**
 * Finds the PublicSquare card ID a payment charges: a card entered in the admin, a customer's saved card, or a card
 * entered at checkout.
 */
class CardResolver
{
    /**
     * @var PaymentTokenManagementInterface
     */
    private $tokenManagement;

    /**
     * @var RequirementResolver
     */
    private $requirementResolver;

    /**
     * @var Logger
     */
    private $logger;

    public function __construct(
        PaymentTokenManagementInterface $tokenManagement,
        RequirementResolver $requirementResolver,
        Logger $logger
    ) {
        $this->tokenManagement = $tokenManagement;
        $this->requirementResolver = $requirementResolver;
        $this->logger = $logger->withName('PSQ:CardResolver');
    }

    /**
     * The card ID that $payment charges, or null if it has none or its saved card can't be used.
     *
     * @param InfoInterface $payment
     * @param int|string|null $customerId The customer placing the order; null for a guest
     */
    public function getCardId($payment, $customerId): ?string
    {
        if ($publicHash = $payment->getAdditionalInformation('public_hash')) {
            return $this->getSavedCardId($publicHash, $customerId);
        }
        // A saved-card payment names its card only by public hash, so a client can't send a card ID instead.
        if ($payment->getMethod() === Config::VAULT_CODE) {
            return null;
        }
        if ($nonce = $payment->getAdditionalInformation('payment_method_nonce')) {
            return $nonce;
        }
        return $payment->getAdditionalInformation('cardId') ?: null;
    }

    /**
     * The card ID of the customer's saved PublicSquare card, or null if they have no such active card.
     *
     * @param int|string|null $customerId
     */
    public function getSavedCardId(string $publicHash, $customerId): ?string
    {
        // Guests have no saved cards.
        if (!$customerId) {
            return null;
        }
        // Null for an unknown hash or another customer's.
        $token = $this->tokenManagement->getByPublicHash($publicHash, (int) $customerId);
        if (!$token) {
            return null;
        }
        // Deleting a card in My Account only deactivates its token, so an old public hash still finds it.
        if (!$token->getIsActive()) {
            $this->logger->info('Saved card refused: the customer deleted it', ['customer_id' => $customerId]);
            return null;
        }
        if (!$this->requirementResolver->isPublicSquareCard($token)) {
            $this->logger->info('Saved card refused: it belongs to another payment method', [
                'customer_id' => $customerId,
            ]);
            return null;
        }
        return (string) $token->getGatewayToken();
    }
}
