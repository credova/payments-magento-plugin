/**
 * PublicSquare Financial method
 *
 * @category  PublicSquare
 * @package   PublicSquare_Payments
 * @author    PublicSquare <info@publicsquare.com>
 * @copyright 2024 PublicSquare
 * @license   http://opensource.org/licenses/osl-3.0.php (OSL 3.0)
 * @link https://publicsquare.com/
 */
/* @api */
define([
  'jquery',
  'Magento_Payment/js/view/payment/cc-form',
  'PublicSquare_Payments/js/publicsquare_payments',
  'Magento_Checkout/js/model/url-builder',
  'mage/storage',
  'Magento_Checkout/js/model/quote',
  'Magento_Checkout/js/model/payment/additional-validators',
  'Magento_Checkout/js/model/full-screen-loader',
  'mage/translate',
  'Magento_Vault/js/view/payment/vault-enabler',
  'Magento_Ui/js/model/messageList',
  'Magento_Customer/js/model/customer',
  'Magento_Checkout/js/model/place-order',
], function (
  $,
  Component,
  publicsquare,
  urlBuilder,
  storage,
  quote,
  additionalValidators,
  fullScreenLoader,
  $t,
  VaultEnabler,
  messageList,
  customer,
  placeOrderService,
) {
  'use strict';

  return Component.extend({
    defaults: {
      template: 'PublicSquare_Payments/payment/publicsquare_payments',
      paymentPayload: {
        nonce: null,
      },
      additionalData: {},
      apiKey: window.checkoutConfig.payment.publicsquare_payments.pk,
      code: 'publicsquare_payments',
      elementsFormSelector: '#publicsquare-elements-form',
      vaultName: 'publicsquare_payments',
      errorMessage: 'Something went wrong. Please try again or contact support for assistance.',
      submitting: false,
      idempotencyKey: null,
      cardId: null,
    },
    initialize: function () {
      const self = this;
      self._super();
      this.vaultEnabler = new VaultEnabler();
      this.vaultEnabler.setPaymentCode(this.getVaultCode());
      this.vaultEnabler.isActivePaymentTokenEnabler(false);
      self.idempotencyKey = self.generateIdempotencyKey();
      return self;
    },
    onContainerRendered: function () {
      const cardInputCustomization = JSON.parse(
        window.checkoutConfig.payment.publicsquare_payments.cardInputCustomization || '{}',
      );
      console.log('psq: Got cardInputCustomization: %j', cardInputCustomization);
      // This runs when the container div on the checkout page renders
      publicsquare.initElements(
        {
          apiKey: this.apiKey,
          selector: this.elementsFormSelector,
          cardInputCustomization,
        },
        () => {},
      );
    },
    createCard: function (cardholder_name) {
      return publicsquare.createCard(cardholder_name, publicsquare.cardElement);
    },
    placeOrder: async function () {
      const self = this;
      if (self.submitting) return;
      console.log('publicsquare_payments-method: Begin validation...');
      if (self.validate() && additionalValidators.validate()) {
        console.log('publicsquare_payments-method: Validation passed');
        self.submitting = true;
        fullScreenLoader.startLoader();
        const billingAddress = quote.billingAddress();
        try {
          // Tokenize the card in PublicSquare
          const card = await this.createCard(`${billingAddress.firstname} ${billingAddress.lastname}`);
          // Submit the payment
          await self.placeOrderWithCardId(card.id);
        } catch (error) {
          fullScreenLoader.stopLoader();
          messageList.addErrorMessage({
            message: $t(
              error.responseJSON && error.responseJSON.message ? error.responseJSON.message : self.errorMessage,
            ),
          });
          self.idempotencyKey = self.generateIdempotencyKey();
          self.submitting = false;
        }
      } else {
        messageList.addErrorMessage({
          message: $t('Please check your checkout details.'),
        });
        return false;
      }
    },
    placeOrderWithCardId: function (cardId) {
      const self = this;
      self.cardId = cardId;
      console.log('publicsquare_payments-method: Submitting order');

      const serviceUrl = urlBuilder.createUrl(
        customer.isLoggedIn() ? '/carts/mine/payment-information' : '/guest-carts/:quoteId/payment-information',
        {
          quoteId: quote.getQuoteId(),
        },
      );
      const placeOrderReqBody = {
        paymentMethod: self.getData(),
      };
      if (customer.isLoggedIn()) {
        console.log('publicsquare_payments-method: Customer is logged in');
        if (quote.getItems().every((_) => _.product_type === 'virtual')) {
          let billingAddress = quote.billingAddress();

          console.log('publicsquare_payments-method: Found address on quote! %j', billingAddress);
          placeOrderReqBody.billingAddress = billingAddress;
        }
      } else {
        placeOrderReqBody.email = quote.guestEmail;
      }
      // Handle failure in then() so the chain settles; a rejection would reach placeOrder's catch
      // and show a second error.
      return placeOrderService(serviceUrl, placeOrderReqBody, messageList).then(
        () => {
          const maskId = window.checkoutConfig.quoteData.entity_id;
          const successUrl = `${window.checkoutConfig.payment.publicsquare_payments.successUrl}?${window.checkoutConfig.isCustomerLoggedIn ? 'refercust' : 'refergues'}=${maskId}`;
          $.mage.redirect(successUrl);
        },
        function (response) {
          fullScreenLoader.stopLoader();
          self.idempotencyKey = self.generateIdempotencyKey();
          self.submitting = false;

          // Extract the error message from the response
          let errorMessage = self.errorMessage;
          if (response.responseJSON && response.responseJSON.message) {
            try {
              // Sometimes the message might be JSON encoded
              const decodedMessage = JSON.parse(response.responseJSON.message);
              errorMessage = decodedMessage.message || decodedMessage;
            } catch {
              // If not JSON, use the message directly
              errorMessage = response.responseJSON.message;
            }
          }
          console.log('publicsquare_payments-method: Failed to place order! %j', errorMessage);

          messageList.addErrorMessage({
            message: $t(errorMessage),
          });
        },
      );
    },
    /**
     * @returns {Object}
     */
    getData: function () {
      const data = {
        method: this.getCode(),
        additional_data: {
          cardId: this.cardId,
          idempotencyKey: this.idempotencyKey,
          saveCard: this.vaultEnabler.isActivePaymentTokenEnabler(),
        },
        ...(window.checkoutConfig.checkoutAgreements &&
          window.checkoutConfig.checkoutAgreements.agreements && {
            extension_attributes: {
              agreement_ids: window.checkoutConfig.checkoutAgreements.agreements.map(({ agreementId }) => agreementId),
            },
          }),
      };

      data['additional_data'] = _.extend(data['additional_data'], this.additionalData);
      this.vaultEnabler.visitAdditionalData(data);
      if (data.saveCard) {
        data['additional_data']['saveCard'] = true;
      }

      return data;
    },
    /**
     * @returns {Boolean}
     */
    isVaultEnabled: function () {
      return this.vaultEnabler.isVaultEnabled();
    },
    /**
     * Returns vault code.
     *
     * @returns {String}
     */
    getVaultCode: function () {
      return window.checkoutConfig.payment[this.getCode()].ccVaultCode;
    },
    /**
     * Return Payment method code
     *
     * @returns {*}
     */
    getCode: function () {
      return this.code;
    },
    generateIdempotencyKey() {
      const timestamp = Date.now().toString();
      const random = Math.random().toString(36).substr(2, 9);
      return timestamp + random;
    },
  });
});
