<?php

namespace PublicSquare\Payments\Dev;

use Magento\Framework\Exception\NoSuchEntityException;

/**
 * DEV ONLY (spike, not for merge): receives the card's modified_at after updateCvc. See Dev/README.md.
 */
class CvcUpdatedReporter implements CvcUpdatedReporterInterface
{
    private FreshCvcSimulator $simulator;

    public function __construct(FreshCvcSimulator $simulator)
    {
        $this->simulator = $simulator;
    }

    public function report(int $customerId, string $cardId, string $modifiedAt): bool
    {
        if (!$this->simulator->isEnabled()) {
            throw new NoSuchEntityException(__('Not found.'));
        }
        $this->simulator->recordCvcUpdated($cardId, $modifiedAt);
        return true;
    }
}
