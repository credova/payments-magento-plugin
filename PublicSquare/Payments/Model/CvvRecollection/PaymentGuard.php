<?php

namespace PublicSquare\Payments\Model\CvvRecollection;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use PublicSquare\Payments\Exception\CvvRecollectionRequiredException;
use PublicSquare\Payments\Helper\Config;
use PublicSquare\Payments\Logger\Logger;

/**
 * Enforces CVV re-entry on the server when a saved-card payment is charged.
 *
 * The browser can be skipped (a saved-card order is a plain REST call), so this re-runs the same rule
 * with the order's own shipping address. When a CVV is required:
 * - no stamp for this cart and card means checkout never asked for the CVV, so the payment is rejected;
 * - otherwise the stamp is recorded on the payment, and, when enabled, sent to PublicSquare as
 *   require_fresh_cvc so it can reject a payment whose card wasn't updated after the stamp.
 *
 * Orders not placed by a shopper (admin, integrations, cron, CLI) are skipped: nobody is there to enter
 * a CVV. That includes subscription renewals created by a back-office process.
 */
class PaymentGuard
{
    private const SHOPPER_AREAS = [Area::AREA_FRONTEND, Area::AREA_WEBAPI_REST, Area::AREA_GRAPHQL];

    private UserContextInterface $userContext;
    private State $appState;
    private PaymentTokenManagementInterface $tokenManagement;
    private RequirementResolver $resolver;
    private CvvRequestStore $requestStore;
    private Config $config;
    private Logger $logger;

    public function __construct(
        UserContextInterface $userContext,
        State $appState,
        PaymentTokenManagementInterface $tokenManagement,
        RequirementResolver $resolver,
        CvvRequestStore $requestStore,
        Config $config,
        Logger $logger,
    ) {
        $this->userContext = $userContext;
        $this->appState = $appState;
        $this->tokenManagement = $tokenManagement;
        $this->resolver = $resolver;
        $this->requestStore = $requestStore;
        $this->config = $config;
        $this->logger = $logger->withName('PSQ:CvvPaymentGuard');
    }

    /**
     * Checks a saved-card payment before it's charged.
     *
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param string $publicHash The saved card's public hash
     * @return array{updated_after: string, max_age_seconds: int}|null The require_fresh_cvc option to send, if any
     * @throws CvvRecollectionRequiredException When a CVV is required but checkout never asked for it
     */
    public function check($payment, string $publicHash): ?array
    {
        $order = $payment->getOrder();
        $customerId = (int)$order->getCustomerId();
        if (!$customerId || !$this->isPlacedByShopper()) {
            return null;
        }
        $token = $this->tokenManagement->getByPublicHash($publicHash, $customerId);
        if (!$token) {
            return null;
        }

        $storeId = $order->getStoreId();
        $orderAddress = $order->getIsVirtual() ? null : $order->getShippingAddress();
        $shippingAddress = $orderAddress ? ShippingAddress::fromAddress($orderAddress) : null;
        if (!$this->resolver->isRequired($customerId, $token, $shippingAddress, $storeId)) {
            return null;
        }

        $requestedAt = $this->requestStore->getRequestedAt((int)$order->getQuoteId(), (int)$token->getEntityId());
        if ($requestedAt === null) {
            $this->logger->warning('Saved-card payment needs a CVV, but checkout never asked for it', [
                'quote_id' => $order->getQuoteId(),
                'payment_token_id' => $token->getEntityId(),
            ]);
            throw CvvRecollectionRequiredException::create();
        }

        $payment->setAdditionalInformation('cvvRecollectionRequired', true);
        $payment->setAdditionalInformation('cvvRequestedAt', $requestedAt->format(DATE_ATOM));

        if (!$this->config->isFreshCvcCheckEnabled(ScopeInterface::SCOPE_STORE, $storeId)) {
            return null;
        }
        return [
            'updated_after' => $requestedAt->format('Y-m-d\TH:i:s\Z'),
            'max_age_seconds' => $this->config->getFreshCvcMaxAgeSeconds(ScopeInterface::SCOPE_STORE, $storeId),
        ];
    }

    private function isPlacedByShopper(): bool
    {
        if ((int)$this->userContext->getUserType() !== UserContextInterface::USER_TYPE_CUSTOMER) {
            return false;
        }
        try {
            return in_array($this->appState->getAreaCode(), self::SHOPPER_AREAS, true);
        } catch (LocalizedException $e) {
            return false;
        }
    }
}
