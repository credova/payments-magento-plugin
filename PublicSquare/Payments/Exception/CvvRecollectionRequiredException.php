<?php

namespace PublicSquare\Payments\Exception;

use Magento\Payment\Gateway\Command\CommandException;

/**
 * A saved-card payment needs the card's CVV re-entered first.
 *
 * Thrown when checkout never asked for the CVV, or when PublicSquare rejects the payment because the
 * CVV wasn't updated after it was asked for (cvv_recollection_required). The message is shown to the
 * customer, and checkout responds by showing the CVV field.
 */
class CvvRecollectionRequiredException extends CommandException
{
    public const MESSAGE = "Please re-enter your card's security code to continue.";

    public static function create(): self
    {
        return new self(__(self::MESSAGE));
    }
}
