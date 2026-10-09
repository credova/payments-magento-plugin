<?php

namespace PublicSquare\Payments\Dev;

use Magento\Checkout\Model\ConfigProviderInterface;

/**
 * DEV ONLY (spike, not for merge): tells checkout to report CVV refreshes. See Dev/README.md.
 */
class CheckoutConfigProvider implements ConfigProviderInterface
{
    private CvcStepUpRuleSimulator $simulator;

    public function __construct(CvcStepUpRuleSimulator $simulator)
    {
        $this->simulator = $simulator;
    }

    public function getConfig()
    {
        return ['payment' => ['publicsquare_payments' => ['devSimulateCvcStepUpRule' => $this->simulator->isEnabled()]]];
    }
}
