<?php

namespace PublicSquare\Payments\Dev;

use Magento\Framework\Exception\NoSuchEntityException;

/**
 * DEV ONLY (spike, not for merge): checkout reports each CVV refresh, standing in for Basis Theory's token update
 * time. See Dev/README.md.
 */
class CvcUpdatedReporter implements CvcUpdatedReporterInterface
{
    private CvcStepUpRuleSimulator $simulator;

    public function __construct(CvcStepUpRuleSimulator $simulator)
    {
        $this->simulator = $simulator;
    }

    public function report(int $customerId, string $cardId): bool
    {
        if (!$this->simulator->isEnabled()) {
            throw new NoSuchEntityException(__('Not found.'));
        }
        $this->simulator->recordCvcRefreshed($cardId);
        return true;
    }
}
