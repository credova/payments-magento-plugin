<?php

namespace PublicSquare\Payments\Dev;

use Magento\Checkout\Model\ConfigProviderInterface;

/**
 * DEV ONLY (spike, not for merge): tells checkout to report updateCvc results. See Dev/README.md.
 */
class CheckoutConfigProvider implements ConfigProviderInterface
{
    private FreshCvcSimulator $simulator;

    public function __construct(FreshCvcSimulator $simulator)
    {
        $this->simulator = $simulator;
    }

    public function getConfig()
    {
        return ['payment' => ['publicsquare_payments' => ['devSimulateRequireFreshCvc' => $this->simulator->isEnabled()]]];
    }
}
