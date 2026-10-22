<?php

namespace PublicSquare\Payments\Exception;

use Magento\Payment\Gateway\Command\CommandException;

/**
 * PublicSquare rejected a saved-card payment until the shopper re-enters the card's CVV: its card security code rule
 * (fraud_details.rules[].requires_action = card_security_code). The message is shown to the shopper, and checkout
 * asks for the CVV again before the retry.
 */
class CvvRecollectionRequiredException extends CommandException
{
    public const MESSAGE = "Please re-enter your card's security code to continue.";

    public static function create(): self
    {
        return new self(__(self::MESSAGE));
    }
}
