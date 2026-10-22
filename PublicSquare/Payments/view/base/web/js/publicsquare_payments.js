// Copyright © PublicSquare Financial, LLC
//
// @package    PublicSquare_Payments
// @version    4.0.8
define(['publicsquarejs'], function (publicsquarejs) {
  'use strict';

  // Basis Theory brand names; the CVV element uses them to pick 3 or 4 digits.
  const CVC_ELEMENT_BRANDS = {
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

  // The theme can't style the CVV iframe, so it copies the container's text style.
  function cvcElementStyle(selector) {
    const container = document.querySelector(selector);
    if (!container) {
      return null;
    }
    const computed = window.getComputedStyle(container);
    const base = {};
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

    // Loads the SDK once for the card form and the CVV field. A failed load can be retried.
    ensureInitialized: function (apiKey) {
      const self = this;
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

    createCvcElement: function (selector, cardBrand) {
      if (!this.publicsquareJs) {
        throw new Error('PublicSquare not initialized yet');
      }
      const options = {};
      const brand =
        CVC_ELEMENT_BRANDS[
          String(cardBrand || '')
            .toLowerCase()
            .replace(/[_\s]+/g, '-')
        ];
      if (brand) {
        options.cardBrand = brand;
      }
      const style = cvcElementStyle(selector);
      if (style) {
        options.style = style;
      }
      const element = this.publicsquareJs.createElement('cardVerificationCode', options);
      element.mount(selector);
      return element;
    },

    // Returns { error } on failure instead of throwing.
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
