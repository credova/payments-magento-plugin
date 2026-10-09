<?php

namespace PublicSquare\Payments\Model\CvvRecollection;

use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Remembers, for the shopper's checkout session, which cart and card PublicSquare asked to re-enter the CVV for.
 *
 * A failed order reaches the browser only as a message, so checkout can't tell this rejection apart. After a failed
 * order, checkout asks the requirement endpoint again, and the endpoint asks for the CVV for a card remembered here,
 * even where our own check didn't expect PublicSquare to.
 */
class CvvRequestedByPublicSquare
{
    private const SESSION_KEY = 'publicsquare_cvv_requested';

    private CheckoutSession $session;

    public function __construct(CheckoutSession $session)
    {
        $this->session = $session;
    }

    public function remember(int $quoteId, string $cardId): void
    {
        $requested = (array)$this->session->getData(self::SESSION_KEY);
        $requested[$this->key($quoteId, $cardId)] = true;
        $this->session->setData(self::SESSION_KEY, $requested);
    }

    public function wasRequested(int $quoteId, string $cardId): bool
    {
        return isset(((array)$this->session->getData(self::SESSION_KEY))[$this->key($quoteId, $cardId)]);
    }

    private function key(int $quoteId, string $cardId): string
    {
        return $quoteId . ':' . $cardId;
    }
}
