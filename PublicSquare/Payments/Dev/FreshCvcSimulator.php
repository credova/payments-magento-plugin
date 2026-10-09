<?php

namespace PublicSquare\Payments\Dev;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;

/**
 * DEV ONLY (spike, not for merge): simulates payments-api's require_fresh_cvc check. See Dev/README.md.
 */
class FreshCvcSimulator
{
    public const CONFIG_PATH = 'dev/publicsquare/simulate_require_fresh_cvc';
    private const CACHE_PREFIX = 'psq_dev_cvc_updated_';
    private const GRACE_SECONDS = 60;

    private ScopeConfigInterface $scopeConfig;
    private State $appState;
    private CacheInterface $cache;

    public function __construct(ScopeConfigInterface $scopeConfig, State $appState, CacheInterface $cache)
    {
        $this->scopeConfig = $scopeConfig;
        $this->appState = $appState;
        $this->cache = $cache;
    }

    public function isEnabled(): bool
    {
        return $this->appState->getMode() === State::MODE_DEVELOPER
            && (bool)$this->scopeConfig->getValue(self::CONFIG_PATH);
    }

    public function recordCvcUpdated(string $cardId, string $modifiedAt): void
    {
        $this->cache->save($modifiedAt, self::CACHE_PREFIX . $cardId, [], 3600);
    }

    /**
     * The R2 rule: the card's CVV update must be at or after the cutoff.
     *
     * @param array{updated_after: string, max_age_seconds: int} $requireFreshCvc
     */
    public function isFresh(string $cardId, array $requireFreshCvc): bool
    {
        $modifiedAt = $this->cache->load(self::CACHE_PREFIX . $cardId);
        if (!$modifiedAt) {
            return false;
        }
        $cutoff = max(
            strtotime($requireFreshCvc['updated_after']) - self::GRACE_SECONDS,
            time() - (int)$requireFreshCvc['max_age_seconds']
        );
        return strtotime($modifiedAt) >= $cutoff;
    }
}
