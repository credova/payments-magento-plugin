<?php

namespace PublicSquare\Payments\Model\CvvRecollection;

/**
 * A saved card's successful payments on a customer's orders, as PublicSquare's card security code rule counts them.
 */
class CardPaymentHistory
{
    /** Number of successful payments, with or without a shipping address */
    public readonly int $successfulPayments;

    /** @var ShippingAddress[] Where the most recent successful payments shipped to, newest first */
    public readonly array $shippingAddresses;

    /**
     * @param ShippingAddress[] $shippingAddresses
     */
    public function __construct(int $successfulPayments, array $shippingAddresses)
    {
        $this->successfulPayments = $successfulPayments;
        $this->shippingAddresses = $shippingAddresses;
    }
}
