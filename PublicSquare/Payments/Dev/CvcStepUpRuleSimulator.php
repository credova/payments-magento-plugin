<?php

namespace PublicSquare\Payments\Dev;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddress;
use PublicSquare\Payments\Model\CvvRecollection\ShippingAddressHistory;

/**
 * DEV ONLY (spike, not for merge): stands in for PublicSquare's card security code rule until it is enabled on the
 * Test account. See Dev/README.md.
 *
 * The rule rejects a payment when the card has an earlier successful payment, ships to an address none of those
 * payments shipped to, and the CVV wasn't refreshed in the last 30 minutes after the card's last successful payment
 * and last rule reject. Basis Theory isn't read here: checkout reports each CVV refresh instead.
 */
class CvcStepUpRuleSimulator
{
    public const CONFIG_PATH = 'dev/publicsquare/simulate_cvc_step_up_rule';
    private const CACHE_PREFIX = 'psq_dev_cvc_rule_';
    private const REFRESH_WINDOW_SECONDS = 30 * 60;

    private ScopeConfigInterface $scopeConfig;
    private State $appState;
    private CacheInterface $cache;
    private ResourceConnection $resourceConnection;
    private ShippingAddressHistory $history;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        State $appState,
        CacheInterface $cache,
        ResourceConnection $resourceConnection,
        ShippingAddressHistory $history,
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->appState = $appState;
        $this->cache = $cache;
        $this->resourceConnection = $resourceConnection;
        $this->history = $history;
    }

    public function isEnabled(): bool
    {
        return $this->appState->getMode() === State::MODE_DEVELOPER
            && (bool)$this->scopeConfig->getValue(self::CONFIG_PATH);
    }

    public function recordCvcRefreshed(string $cardId): void
    {
        $this->save($cardId, 'refreshed', time());
    }

    public function recordPayment(string $cardId): void
    {
        $this->save($cardId, 'paid', time());
    }

    public function recordReject(string $cardId): void
    {
        $this->save($cardId, 'rejected', time());
    }

    /**
     * @param array<string, mixed> $request The POST /payments body
     */
    public function rejects(array $request): bool
    {
        $cardId = (string)($request['payment_method']['card'] ?? '');
        $shipping = $request['shipping_address'] ?? null;
        $customerId = $this->customerIdForCard($cardId);
        if ($cardId === '' || !$shipping || !$customerId) {
            return false;
        }

        $history = $this->history->getCardPaymentHistory($customerId, $cardId);
        if ($history->successfulPayments === 0) {
            return false;
        }
        $address = new ShippingAddress(
            $shipping['address_line_1'] ?? null,
            $shipping['postal_code'] ?? null,
            $shipping['country'] ?? null
        );
        foreach ($history->shippingAddresses as $previous) {
            if ($previous->isSameAddressAs($address)) {
                return false;
            }
        }

        $refreshed = $this->load($cardId, 'refreshed');
        $counts = $refreshed > time() - self::REFRESH_WINDOW_SECONDS
            && $refreshed > $this->load($cardId, 'paid')
            && $refreshed > $this->load($cardId, 'rejected');
        return !$counts;
    }

    private function customerIdForCard(string $cardId): int
    {
        $connection = $this->resourceConnection->getConnection();
        return (int)$connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('vault_payment_token'), ['customer_id'])
                ->where('gateway_token = ?', $cardId)
                ->limit(1)
        );
    }

    private function save(string $cardId, string $event, int $time): void
    {
        $this->cache->save((string)$time, self::CACHE_PREFIX . $event . '_' . $cardId, [], 86400);
    }

    private function load(string $cardId, string $event): int
    {
        return (int)$this->cache->load(self::CACHE_PREFIX . $event . '_' . $cardId);
    }
}
