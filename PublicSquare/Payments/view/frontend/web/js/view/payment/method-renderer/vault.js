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
  additionalValidators,
  publicsquare,
) {
  'use strict';

  return VaultComponent.extend({
    defaults: {
      template: 'PublicSquare_Payments/payment/vault',
      modules: {
        hostedFields: '${ $.parentName }.publicsquare_payments',
      },
      additionalData: {},
      idempotencyKey: null,
      // CVV re-entry for a saved card shipping to a new address
      requiresCvc: false,
      cvcErrorMessage: '',
      cvcElement: null,
      cvcComplete: false,
      cvcCardId: null,
      cvcMounting: null,
      submitting: false,
    },

    initObservable: function () {
      this._super().observe(['requiresCvc', 'cvcErrorMessage']);
      return this;
    },

    initialize: function () {
      var self = this;
      self._super();
      self.idempotencyKey = self.generateIdempotencyKey();
      if (self.isCvvRecollectionEnabled()) {
        self.watchForCvvChanges();
      }
      return self;
    },

    /**
     * Keeps the CVV field in step with checkout: another payment method hides it, and a saved change to
     * the cart (shipping address, totals) re-checks whether this card needs its CVV.
     */
    watchForCvvChanges: function () {
      var self = this;
      quote.paymentMethod.subscribe(function (method) {
        if (!method || method.method !== self.getId()) {
          self.resetCvc();
        }
      });
      quote.totals.subscribe(function () {
        if (self.isSelected()) {
          self.checkCvvRequirement();
        }
      });
      if (self.isSelected()) {
        self.checkCvvRequirement();
      }
    },

    isSelected: function () {
      var method = quote.paymentMethod();
      return !!method && method.method === this.getId();
    },

    /**
     * Whether the store asks for the CVV when a saved card ships to a new address.
     * @returns {Boolean}
     */
    isCvvRecollectionEnabled: function () {
      return !!window.checkoutConfig.payment.publicsquare_payments.requireCvvForNewShippingAddress;
    },

    getCvcContainerId: function () {
      return 'psq-cvc-' + this.getId();
    },

    selectPaymentMethod: function () {
      var result = this._super();
      this.checkCvvRequirement();
      return result;
    },

    /**
     * Asks the server whether this card needs its CVV re-entered for the cart's shipping address,
     * and shows the CVV field when it does. The server records when it asked.
     * @returns {Promise<Boolean>} Whether a CVV is required
     */
    checkCvvRequirement: function () {
      var self = this;
      if (!self.isCvvRecollectionEnabled()) {
        return Promise.resolve(false);
      }
      return new Promise(function (resolve) {
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
            // The server checks again when the order is placed, so checkout carries on.
            resolve(self.requiresCvc());
          });
      });
    },

    /**
     * Mounts the CVV field once, even when several checks finish at the same time.
     */
    mountCvcElement: function (cardBrand) {
      var self = this;
      if (self.cvcElement) {
        return Promise.resolve();
      }
      if (!self.cvcMounting) {
        self.cvcMounting = publicsquare
          .ensureInitialized(window.checkoutConfig.payment.publicsquare_payments.pk)
          .then(function () {
            // The customer may have picked something else while the SDK loaded.
            if (self.cvcElement || !self.requiresCvc()) {
              return;
            }
            self.cvcComplete = false;
            self.cvcElement = publicsquare.createCvcElement('#' + self.getCvcContainerId(), cardBrand);
            self.cvcElement.on('change', function (event) {
              self.cvcComplete = !!(event && event.complete);
              if (self.cvcComplete) {
                self.cvcErrorMessage('');
              }
            });
          })
          .catch(function () {
            self.cvcErrorMessage(
              $t('The security code field could not be loaded. Please refresh the page and try again.'),
            );
          })
          .finally(function () {
            self.cvcMounting = null;
          });
      }
      return self.cvcMounting;
    },

    resetCvc: function () {
      if (this.cvcElement) {
        this.cvcElement.unmount();
      }
      this.cvcElement = null;
      this.cvcComplete = false;
      this.cvcCardId = null;
      this.cvcErrorMessage('');
      this.requiresCvc(false);
    },

    /**
     * Sends the re-entered CVV to the saved card before the order is placed.
     * @returns {Promise<Boolean>} Whether the order can be placed
     */
    attachCvc: async function () {
      if (!this.requiresCvc()) {
        return true;
      }
      if (!this.cvcElement || !this.cvcComplete) {
        this.cvcErrorMessage($t("Enter the card's security code."));
        return false;
      }
      var result = await publicsquare.updateCvc(this.cvcCardId, this.cvcElement);
      if (!result || result.error) {
        this.cvcErrorMessage($t("We couldn't save the security code. Please check it and try again."));
        return false;
      }
      // DEV ONLY (spike, not for merge): feeds the simulated require_fresh_cvc check. See Dev/README.md.
      if (window.checkoutConfig.payment.publicsquare_payments.devSimulateRequireFreshCvc) {
        await storage.post(
          urlBuilder.createUrl('/publicsquare/carts/mine/dev/cvc-updated', {}),
          JSON.stringify({ cardId: this.cvcCardId, modifiedAt: result.modified_at }),
          false,
        );
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
      var self = this;
      if (self.submitting || !additionalValidators.validate()) {
        return;
      }
      self.submitting = true;

      self.hostedFields(async () => {
        // Magento's loader is reference-counted: every start needs a stop, or the overlay stays up.
        fullScreenLoader.startLoader();
        var cvcAttached = false;
        try {
          cvcAttached = await self.attachCvc();
        } catch {
          messageList.addErrorMessage({
            message: $t('Something went wrong. Please try again or contact support for assistance.'),
          });
        } finally {
          fullScreenLoader.stopLoader();
        }
        if (!cvcAttached) {
          self.submitting = false;
          return;
        }
        self.placeOrderWithCardId().always(function () {
          self.submitting = false;
        });
      });
    },

    placeOrderWithCardId: function () {
      var self = this;
      fullScreenLoader.startLoader();
      var serviceUrl = urlBuilder.createUrl(
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
        messageList,
      )
        .done(function () {
          // Handle successful order placement
          const maskId = window.checkoutConfig.quoteData.entity_id;
          const successUrl = `${window.checkoutConfig.payment.publicsquare_payments.successUrl}?${window.checkoutConfig.isCustomerLoggedIn ? 'refercust' : 'refergues'}=${maskId}`;
          $.mage.redirect(successUrl);
        })
        .fail(function (response) {
          // The server's own message (already shown) explains what to fix; only fall back when there's none.
          if (!(response && response.responseJSON && response.responseJSON.message)) {
            messageList.addErrorMessage({
              message: $t('Something went wrong. Please try again or contact support for assistance.'),
            });
          }
          self.recoverFromFailedOrder();
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
      var data = {
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

    /**
     * After a decline or a "re-enter your security code" error, lets the customer fix it and retry:
     * a fresh idempotency key so the retry is a new payment, an empty CVV field so the CVV is entered
     * again, and a re-check in case the server now needs the CVV.
     */
    recoverFromFailedOrder: function () {
      this.idempotencyKey = this.generateIdempotencyKey();
      if (!this.isCvvRecollectionEnabled()) {
        return;
      }
      if (this.cvcElement && typeof this.cvcElement.clear === 'function') {
        this.cvcElement.clear();
        this.cvcComplete = false;
      }
      this.checkCvvRequirement();
    },

    generateIdempotencyKey() {
      const timestamp = Date.now().toString();
      const random = Math.random().toString(36).substr(2, 9);
      return timestamp + random;
    },
  });
});
