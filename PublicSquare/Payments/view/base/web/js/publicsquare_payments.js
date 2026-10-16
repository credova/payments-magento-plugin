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

  // The CVV input is inside an iframe, out of reach of the theme's styles. It takes its text style from the
  // container, which the theme styles as one of its inputs.
  function cvcElementStyle(selector) {
    var container = document.querySelector(selector);
    if (!container) {
      return null;
    }
    var computed = window.getComputedStyle(container);
    var base = {};
    if (computed.color) {
      base.color = computed.color;
    }
    if (computed.fontFamily) {
      base.fontFamily = computed.fontFamily;
    }
    if (computed.fontSize) {
      base.fontSize = computed.fontSize;
    }
    return Object.keys(base).length ? { base: base } : null;
  }

  // Replaces the card element. It is kept only once it mounts, so a failed mount leaves no half-made element.
  function mountCardElement(payments, params) {
    if (payments.cardElement) {
      payments.cardElement.unmount();
      payments.cardElement = null;
    }
    const cardElement = payments.publicsquareJs.createCardElement(params.cardInputCustomization);
    cardElement.mount(params.selector);
    payments.cardElement = cardElement;
  }

  return (window.publicsquare = {
    // Properties
    version: '1.0.0',
    publicsquareJs: null,
    cardElement: null,
    loading: false,
    initializing: null,
    initPromise: null,

    /**
     * Initializes the SDK once, without mounting anything. Every caller (the card form, the saved-card CVV
     * field) shares the same SDK and the same in-flight load. A failed load can be retried.
     * @param {string} apiKey Publishable key
     * @returns {Promise<object>} The initialized SDK
     */
    ensureInitialized: function (apiKey) {
      var self = this;
      if (self.publicsquareJs) {
        return Promise.resolve(self.publicsquareJs);
      }
      if (!self.initPromise) {
        self.initPromise = Promise.resolve(publicsquarejs.init(apiKey))
          .then(function (sdk) {
            self.publicsquareJs = sdk;
            return sdk;
          })
          .finally(function () {
            self.initPromise = null;
          });
      }
      return self.initPromise;
    },

    initElements: async function (params = {}, callback) {
      if (this.initializing) {
        // A call during the SDK load waits for it, so its callback sees the mounted card element.
        await this.initializing;
      } else if (!this.publicsquareJs && !this.loading) {
        this.loading = true;
        this.initializing = (async () => {
          await this.ensureInitialized(params.apiKey);
          mountCardElement(this, params);
        })();
        // Reset on failure too, or a failed SDK load blocks every later attempt.
        try {
          await this.initializing;
        } finally {
          this.loading = false;
          this.initializing = null;
        }
      } else if (!this.loading) {
        // The SDK is ready. This also retries a first mount that failed after the SDK loaded.
        mountCardElement(this, params);
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
      var style = cvcElementStyle(selector);
      if (style) {
        options.style = style;
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
