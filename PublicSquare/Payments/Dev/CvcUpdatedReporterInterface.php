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
     * @param string $modifiedAt
     * @return bool
     */
    public function report(int $customerId, string $cardId, string $modifiedAt): bool;
}
