// Copyright © PublicSquare Financial, LLC
//
// @package    PublicSquare_Payments
// @version    4.0.8
define(['publicsquarejs'], function (publicsquarejs) {
  'use strict';

  // Basis Theory's card brand names, which the CVV element uses to pick 3 or 4 digits.
  var CVC_ELEMENT_BRANDS = {
    visa: 'visa',
    mastercard: 'mastercard',
    'master-card': 'mastercard',
    amex: 'american-express',
    'american-express': 'american-express',
    discover: 'discover',
    diners: 'diners-club',
    'diners-club': 'diners-club',
    jcb: 'jcb',
    unionpay: 'unionpay',
    'union-pay': 'unionpay',
    maestro: 'maestro',
  };

  return (window.publicsquare = {
    // Properties
    version: '1.0.0',
    publicsquareJs: null,
    cardElement: null,
    loading: false,
    initPromise: null,

    /**
     * Initializes the SDK once, without mounting anything. Later calls share the same SDK.
     * @param {string} apiKey Publishable key
     * @returns {Promise<object>} The initialized SDK
     */
    ensureInitialized: function (apiKey) {
      var self = this;
      if (self.publicsquareJs) {
        return Promise.resolve(self.publicsquareJs);
      }
      if (!self.initPromise) {
        self.initPromise = Promise.resolve(publicsquarejs.init(apiKey)).then(
          function (sdk) {
            self.publicsquareJs = sdk;
            return sdk;
          },
          function (error) {
            self.initPromise = null;
            throw error;
          },
        );
      }
      return self.initPromise;
    },

    initElements: async function (params = {}, callback) {
      const sdk = await this.ensureInitialized(params.apiKey);
      if (this.cardElement) {
        this.cardElement.unmount();
      }
      this.cardElement = sdk.createCardElement(params.cardInputCustomization);
      this.cardElement.mount(params.selector);
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

    /**
     * Mounts a standalone CVV field for a saved card.
     * @param {string} selector Where to mount it, e.g. "#psq-cvc-publicsquare_payments_cc_vault_1"
     * @param {string} [cardBrand] The saved card's brand as PublicSquare stores it (e.g. "visa", "amex")
     * @returns {object} The CVV element, for updateCvc
     */
    createCvcElement: function (selector, cardBrand) {
      if (!this.publicsquareJs) {
        throw new Error('PublicSquare not initialized yet');
      }
      var options = {};
      var brand =
        CVC_ELEMENT_BRANDS[
          String(cardBrand || '')
            .toLowerCase()
            .replace(/[_\s]+/g, '-')
        ];
      if (brand) {
        options.cardBrand = brand;
      }
      var element = this.publicsquareJs.createElement('cardVerificationCode', options);
      element.mount(selector);
      return element;
    },

    /**
     * Attaches the CVV the customer just typed to a saved card. The CVV goes straight to Basis Theory.
     * @param {string} cardId PSQ card ID of the saved card
     * @param {object} cvcElement Element from createCvcElement
     * @returns {Promise<object>} The SDK's response; failures come back as { error } rather than throwing
     */
    updateCvc: async function (cardId, cvcElement) {
      if (!this.publicsquareJs) {
        return { error: { error: 'PublicSquare not initialized yet' } };
      }
      try {
        return await this.publicsquareJs.cards.updateCvc(cardId, cvcElement);
      } catch (error) {
        return { error: { error: (error && error.message) || 'Failed to update CVC' } };
      }
    },
  });
});
