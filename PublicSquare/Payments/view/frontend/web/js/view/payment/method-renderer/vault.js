/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define([
  'jquery',
  'Magento_Vault/js/view/payment/method-renderer/vault',
  'Magento_Ui/js/model/messageList',
  'Magento_Checkout/js/model/full-screen-loader',
  'Magento_Checkout/js/model/url-builder',
  'mage/storage',
  'mage/translate',
  'Magento_Customer/js/model/customer',
  'Magento_Checkout/js/model/place-order',
  'Magento_Checkout/js/model/quote',
  'underscore',
  'PublicSquare_Payments/js/model/order-error',
  'Magento_Checkout/js/model/payment/additional-validators',
  'publicsquare_payments',
], function (
  $,
  VaultComponent,
  messageList,
  fullScreenLoader,
  urlBuilder,
  storage,
  $t,
  customer,
  placeOrderService,
  quote,
  _,
  orderError,
  additionalValidators,
  publicsquare,
) {
  'use strict';

  // Magento's place-order shows the raw server error in the message container it gets. This one
  // shows nothing, so the shopper sees only the renderer's message. A 401 still goes to login.
  const placeOrderMessages = { addErrorMessage: () => true };

  return VaultComponent.extend({
    defaults: {
      template: 'PublicSquare_Payments/payment/vault',
      modules: {
        hostedFields: '${ $.parentName }.publicsquare_payments',
      },
      additionalData: {},
      idempotencyKey: null,
      requiresCvc: false,
      cvcErrorMessage: '',
      cvcFocused: false,
      cvcElement: null,
      cvcComplete: false,
      cvcCardId: null,
      cvvCheck: null,
      submitting: false,
    },

    initObservable: function () {
      this._super().observe(['requiresCvc', 'cvcErrorMessage', 'cvcFocused']);
      return this;
    },

    initialize: function () {
      const self = this;
      self._super();
      self.idempotencyKey = self.generateIdempotencyKey();
      return self;
    },

    isCvvRecollectionEnabled: function () {
      return !!window.checkoutConfig.payment.publicsquare_payments.requireCvvForNewShippingAddress;
    },

    getCvcContainerId: function () {
      return 'psq-cvc-' + this.getId();
    },

    selectPaymentMethod: function () {
      const result = this._super();
      this.checkCvvRequirement();
      return result;
    },

    // Asks the server whether this card needs its CVV for the cart's address. Resolves to whether it does.
    checkCvvRequirement: function () {
      const self = this;
      if (!self.isCvvRecollectionEnabled()) {
        return Promise.resolve(false);
      }
      const check = new Promise(function (resolve) {
        storage
          .post(
            urlBuilder.createUrl('/publicsquare/carts/mine/cvv-requirement', {}),
            JSON.stringify({ publicHash: self.publicHash }),
            false,
          )
          .done(function (response) {
            if (response && response.required) {
              self.cvcCardId = response.card_id;
              self.requiresCvc(true);
              self.mountCvcElement(response.card_brand).then(function () {
                resolve(true);
              });
            } else {
              self.resetCvc();
              resolve(false);
            }
          })
          .fail(function () {
            // PublicSquare still enforces the rule on the payment, so a failed check doesn't block checkout.
            resolve(self.requiresCvc());
          });
      });
      // Place Order waits for the latest check, so an order can't go out before the CVV field appears.
      self.cvvCheck = check;
      check.then(function () {
        if (self.cvvCheck === check) {
          self.cvvCheck = null;
        }
      });
      return check;
    },

    mountCvcElement: async function (cardBrand) {
      const self = this;
      if (self.cvcElement) {
        return;
      }
      try {
        await publicsquare.ensureInitialized(window.checkoutConfig.payment.publicsquare_payments.pk);
        self.cvcComplete = false;
        self.cvcElement = publicsquare.createCvcElement('#' + self.getCvcContainerId(), cardBrand);
        self.cvcElement.on('change', function (event) {
          self.cvcComplete = !!(event && event.complete);
          if (self.cvcComplete) {
            self.cvcErrorMessage('');
          }
        });
        // The input is in a cross-origin iframe, so the container can't use :focus-within.
        self.cvcElement.on('focus', function () {
          self.cvcFocused(true);
        });
        self.cvcElement.on('blur', function () {
          self.cvcFocused(false);
        });
      } catch {
        self.cvcErrorMessage($t('The security code field could not be loaded. Please refresh the page and try again.'));
      }
    },

    resetCvc: function () {
      if (this.cvcElement) {
        this.cvcElement.unmount();
      }
      this.cvcElement = null;
      this.cvcComplete = false;
      this.cvcCardId = null;
      this.cvcErrorMessage('');
      this.cvcFocused(false);
      this.requiresCvc(false);
    },

    // Resolves false when the order shouldn't be placed.
    attachCvc: async function () {
      if (!this.requiresCvc()) {
        return true;
      }
      if (!this.cvcElement || !this.cvcComplete) {
        this.cvcErrorMessage($t("Enter the card's security code."));
        return false;
      }
      const result = await publicsquare.updateCvc(this.cvcCardId, this.cvcElement);
      if (!result || result.error) {
        this.cvcErrorMessage($t("We couldn't save the security code. Please check it and try again."));
        return false;
      }
      return true;
    },

    getIcons: function (type) {
      return {
        url: `${window.checkoutConfig.payment.publicsquare_payments.cardImagesBasePath}${type}.svg`,
        width: '45',
        height: '29',
      };
    },

    /**
     * Get last 4 digits of card
     * @returns {String}
     */
    getMaskedCard: function () {
      return this.details.maskedCC;
    },

    /**
     * Get expiration date
     * @returns {String}
     */
    getExpirationDate: function () {
      return this.details.expirationDate;
    },

    /**
     * Get card type
     * @returns {String}
     */
    getCardType: function () {
      return this.details.type;
    },

    /**
     * Place order
     */
    placeOrder: function () {
      const self = this;
      if (self.submitting || !additionalValidators.validate()) {
        return;
      }
      self.submitting = true;
      const done = function () {
        self.submitting = false;
      };

      self.hostedFields(() => {
        const pendingCheck = self.cvvCheck;
        if (!pendingCheck) {
          self.attachCvcAndPlaceOrder(done);
          return;
        }
        // Magento's loader is reference-counted: every start needs a stop, or the overlay stays up.
        fullScreenLoader.startLoader();
        pendingCheck.then(function () {
          fullScreenLoader.stopLoader();
          self.attachCvcAndPlaceOrder(done);
        });
      });
    },

    attachCvcAndPlaceOrder: function (done) {
      const self = this;
      if (!self.requiresCvc()) {
        self.placeOrderWithCardId().always(done);
        return;
      }
      fullScreenLoader.startLoader();
      self
        .attachCvc()
        .catch(function () {
          messageList.addErrorMessage({ message: $t(orderError.message()) });
          return false;
        })
        .then(function (cvcAttached) {
          fullScreenLoader.stopLoader();
          if (!cvcAttached) {
            done();
            return;
          }
          self.placeOrderWithCardId().always(done);
        });
    },

    placeOrderWithCardId: function () {
      const self = this;
      fullScreenLoader.startLoader();
      const serviceUrl = urlBuilder.createUrl(
        customer.isLoggedIn() ? '/carts/mine/payment-information' : '/guest-carts/:quoteId/payment-information',
        {
          quoteId: quote.getQuoteId(),
        },
      );

      return placeOrderService(
        serviceUrl,
        {
          ...(!customer.isLoggedIn() && { email: quote.guestEmail }),
          paymentMethod: this.getData(),
        },
        placeOrderMessages,
      )
        .done(function () {
          // Handle successful order placement
          const maskId = window.checkoutConfig.quoteData.entity_id;
          const successUrl = `${window.checkoutConfig.payment.publicsquare_payments.successUrl}?${window.checkoutConfig.isCustomerLoggedIn ? 'refercust' : 'refergues'}=${maskId}`;
          $.mage.redirect(successUrl);
        })
        .fail(function (response) {
          // After a final failure, a retry with the same key would replay it.
          if (orderError.isFinal(response)) {
            self.idempotencyKey = self.generateIdempotencyKey();
          }
          messageList.addErrorMessage({
            message: $t(orderError.message(response)),
          });
        })
        .always(function () {
          fullScreenLoader.stopLoader();
        });
    },

    /**
     * Get payment method data
     * @returns {Object}
     */
    getData: function () {
      const data = {
        method: this.code,
        additional_data: {
          public_hash: this.publicHash,
          idempotencyKey: this.idempotencyKey,
        },
        ...(window.checkoutConfig.checkoutAgreements &&
          window.checkoutConfig.checkoutAgreements.agreements && {
            extension_attributes: {
              agreement_ids: window.checkoutConfig.checkoutAgreements.agreements.map(({ agreementId }) => agreementId),
            },
          }),
      };

      data['additional_data'] = _.extend(data['additional_data'], this.additionalData);

      return data;
    },

    generateIdempotencyKey() {
      const timestamp = Date.now().toString();
      const random = Math.random().toString(36).substr(2, 9);
      return timestamp + random;
    },
  });
});
