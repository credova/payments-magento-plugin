<?php

namespace PublicSquare\Payments\Dev;

/**
 * DEV ONLY (spike, not for merge). See Dev/README.md.
 */
interface CvcUpdatedReporterInterface
{
    /**
     * @param int $customerId
     * @param string $cardId
     * @return bool
     */
    public function report(int $customerId, string $cardId): bool;
}
