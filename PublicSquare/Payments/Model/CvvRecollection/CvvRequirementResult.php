<?php

namespace PublicSquare\Payments\Model\CvvRecollection;

use PublicSquare\Payments\Api\Data\CvvRequirementResultInterface;

class CvvRequirementResult implements CvvRequirementResultInterface
{
    private bool $required;
    private ?string $cardId;
    private ?string $cardBrand;

    public function __construct(bool $required = false, ?string $cardId = null, ?string $cardBrand = null)
    {
        $this->required = $required;
        $this->cardId = $cardId;
        $this->cardBrand = $cardBrand;
    }

    public function getRequired(): bool
    {
        return $this->required;
    }

    public function getCardId(): ?string
    {
        return $this->cardId;
    }

    public function getCardBrand(): ?string
    {
        return $this->cardBrand;
    }
}
