<?php

namespace PublicSquare\Payments\Api;

/**
 * Checkout asks whether a saved card needs its CVV re-entered for the customer's cart.
 *
 * @api
 */
interface CvvRequirementInterface
{
    /**
     * Checks the customer's active cart against the saved card, and records the request when a CVV is needed.
     *
     * @param int $customerId Set from the authenticated customer, never from the request body.
     * @param string $publicHash The saved card's public hash, as checkout already has it.
     * @return \PublicSquare\Payments\Api\Data\CvvRequirementResultInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the saved card isn't the customer's.
     */
    public function check(int $customerId, string $publicHash);
}
