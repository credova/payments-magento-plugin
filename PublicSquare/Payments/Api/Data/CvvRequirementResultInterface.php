<?php

namespace PublicSquare\Payments\Api\Data;

/**
 * Whether checkout must ask for the saved card's CVV, and what it needs to do so.
 *
 * @api
 */
interface CvvRequirementResultInterface
{
    /**
     * @return bool
     */
    public function getRequired(): bool;

    /**
     * The PSQ card ID for cards.updateCvc; only returned when a CVV is required.
     *
     * @return string|null
     */
    public function getCardId(): ?string;

    /**
     * The card brand as stored on the saved card (for example "visa"); only returned when a CVV is required.
     *
     * @return string|null
     */
    public function getCardBrand(): ?string;
}
