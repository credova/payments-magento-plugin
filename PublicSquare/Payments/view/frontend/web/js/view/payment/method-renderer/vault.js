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
) {
  'use strict';

  // Magento's place-order shows the raw server error in the message container it gets. This one
  // shows nothing, so the shopper sees only the renderer's message. A 401 still goes to login.
  const placeOrderMessages = { addErrorMessage: () => true };

  return VaultComponent.extend({
    defaults: {
      template: 'Magento_Vault/payment/form',
      modules: {
        hostedFields: '${ $.parentName }.publicsquare_payments',
      },
      additionalData: {},
      idempotencyKey: null,
    },

    initialize: function () {
      var self = this;
      self._super();
      self.idempotencyKey = self.generateIdempotencyKey();
      return self;
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

      self.hostedFields(() => {
        self.placeOrderWithCardId();
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

    generateIdempotencyKey() {
      const timestamp = Date.now().toString();
      const random = Math.random().toString(36).substr(2, 9);
      return timestamp + random;
    },
  });
});
