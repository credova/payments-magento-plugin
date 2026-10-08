// Copyright © PublicSquare Financial, LLC
//
// @package    PublicSquare_Payments
// @version    4.0.8
define(['publicsquarejs'], function (publicsquarejs) {
  'use strict';
  return (window.publicsquare = {
    // Properties
    version: '1.0.0',
    publicsquareJs: null,
    cardElement: null,
    loading: false,
    initializing: null,

    initElements: async function (params = {}, callback) {
      if (this.initializing) {
        // A call during the SDK load waits for it, so its callback sees the mounted card element.
        await this.initializing;
      } else if (!this.publicsquareJs && !this.loading) {
        this.loading = true;
        this.initializing = (async () => {
          const _publicsquare = await publicsquarejs.init(params.apiKey);
          this.publicsquareJs = _publicsquare;
          if (this.cardElement) {
            this.cardElement.unmount();
          }
          this.cardElement = _publicsquare.createCardElement(params.cardInputCustomization);
          this.cardElement.mount(params.selector);
        })();
        // Reset on failure too, or a failed SDK load blocks every later attempt.
        try {
          await this.initializing;
        } finally {
          this.loading = false;
          this.initializing = null;
        }
      } else if (!this.loading && this.cardElement) {
        this.cardElement.unmount();
        this.cardElement = this.publicsquareJs.createCardElement(params.cardInputCustomization);
        this.cardElement.mount(params.selector);
      }
      if (typeof callback === 'function') {
        callback(this);
      }
    },
    /**
     * Creates a card object in PublicSquare
     * @param {string} cardHolderName
     * @param {HTMLDivElement} card - This is the card element
     */
    createCard: async function (cardholder_name, card) {
      if (!this.publicsquareJs) {
        throw new Error('PublicSquare not initialized yet');
      } else {
        if (this.loading) {
          throw new Error('PublicSquare is still loading');
        }
        this.loading = true;
        // Reset on failure too, so the shopper can retry after a declined card.
        try {
          return await this.publicsquareJs.cards.create({
            cardholder_name,
            card,
          });
        } finally {
          this.loading = false;
        }
      }
    },
  });
});
