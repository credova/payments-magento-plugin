<?php

namespace PublicSquare\Payments\Model\CvvRecollection;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PublicSquare\Payments\Api\CvvRequirementInterface;
use PublicSquare\Payments\Api\Data\CvvRequirementResultInterface;
use PublicSquare\Payments\Logger\Logger;

/**
 * Answers checkout's "does this saved card need its CVV re-entered?" for the customer's active cart.
 *
 * The shipping address comes from the cart on the server, never from the request. When a CVV is
 * needed, the request is stamped (CvvRequestStore) and the PSQ card ID is returned for updateCvc;
 * otherwise the card ID is not exposed.
 */
class CvvRequirement implements CvvRequirementInterface
{
    private PaymentTokenManagementInterface $tokenManagement;
    private CartRepositoryInterface $cartRepository;
    private RequirementResolver $resolver;
    private CvvRequestStore $requestStore;
    private Logger $logger;

    public function __construct(
        PaymentTokenManagementInterface $tokenManagement,
        CartRepositoryInterface $cartRepository,
        RequirementResolver $resolver,
        CvvRequestStore $requestStore,
        Logger $logger,
    ) {
        $this->tokenManagement = $tokenManagement;
        $this->cartRepository = $cartRepository;
        $this->resolver = $resolver;
        $this->requestStore = $requestStore;
        $this->logger = $logger->withName('PSQ:CvvRequirement');
    }

    public function check(int $customerId, string $publicHash): CvvRequirementResultInterface
    {
        $token = $this->tokenManagement->getByPublicHash($publicHash, $customerId);
        if (!$token || !$token->getIsActive()) {
            throw new NoSuchEntityException(__('The saved card could not be found.'));
        }
        if (!$this->resolver->isPublicSquareCard($token)) {
            return new CvvRequirementResult(false);
        }

        $quote = $this->cartRepository->getActiveForCustomer($customerId);
        $shippingAddress = $this->getShippingAddress($quote);
        if (!$this->resolver->isRequired($customerId, $token, $shippingAddress, $quote->getStoreId())) {
            return new CvvRequirementResult(false);
        }

        $this->requestStore->stamp((int)$quote->getId(), (int)$token->getEntityId());
        $this->logger->info('CVV re-entry requested for saved card', [
            'quote_id' => $quote->getId(),
            'payment_token_id' => $token->getEntityId(),
        ]);

        $details = json_decode((string)$token->getTokenDetails(), true);
        return new CvvRequirementResult(
            true,
            $token->getGatewayToken(),
            is_array($details) ? ($details['type'] ?? null) : null
        );
    }

    /**
     * The cart's shipping address, or null while there isn't one to compare yet (or the cart is virtual).
     *
     * @param \Magento\Quote\Model\Quote $quote
     */
    private function getShippingAddress($quote): ?ShippingAddress
    {
        if ($quote->isVirtual()) {
            return null;
        }
        $address = $quote->getShippingAddress();
        if (!$address || (!$address->getPostcode() && !array_filter((array)$address->getStreet()))) {
            return null;
        }
        return ShippingAddress::fromAddress($address);
    }
}
