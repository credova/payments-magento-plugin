import $ from 'jquery';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadAmdModule } from '../helpers/amd.js';
import { failedPlaceOrder, placeOrderRequest, uiComponent } from '../helpers/magento.js';

const RENDERER =
  'PublicSquare/Payments/view/frontend/web/js/view/payment/method-renderer/publicsquare_payments-method.js';

const GUEST_URL = '/guest-carts/:quoteId/payment-information';
const CUSTOMER_URL = '/carts/mine/payment-information';

let magento;

function checkoutConfig() {
  return {
    payment: {
      publicsquare_payments: {
        pk: 'pk_test_key',
        successUrl: 'https://shop.test/publicsquare/success',
        cardInputCustomization: '{"theme":"dark"}',
        ccVaultCode: 'publicsquare_payments_cc_vault',
      },
    },
    quoteData: { entity_id: 'mask_123' },
    isCustomerLoggedIn: false,
  };
}

/** Builds the renderer with fakes for each Magento model it uses. */
function createRenderer() {
  const vaultEnabler = {
    setPaymentCode: vi.fn(),
    isActivePaymentTokenEnabler: vi.fn(() => false),
    visitAdditionalData: vi.fn(),
    isVaultEnabled: vi.fn(() => true),
  };
  magento = {
    publicsquare: {
      cardElement: { id: 'card-element' },
      initElements: vi.fn(),
      createCard: vi.fn(async () => ({ id: 'card_1' })),
    },
    urlBuilder: { createUrl: vi.fn(() => '/rest/default/V1/payment-information') },
    quote: {
      billingAddress: vi.fn(() => ({ firstname: 'Jane', lastname: 'Doe' })),
      getQuoteId: vi.fn(() => 'quote_1'),
      getItems: vi.fn(() => [{ product_type: 'simple' }]),
      guestEmail: 'jane@example.com',
    },
    additionalValidators: { validate: vi.fn(() => true) },
    fullScreenLoader: { startLoader: vi.fn(), stopLoader: vi.fn() },
    messageList: { addErrorMessage: vi.fn() },
    customer: { isLoggedIn: vi.fn(() => false) },
    placeOrder: vi.fn(() => $.Deferred().resolve().promise()),
    vaultEnabler,
    validate: vi.fn(() => true),
  };

  const Renderer = loadAmdModule(RENDERER, {
    jquery: $,
    'Magento_Payment/js/view/payment/cc-form': uiComponent({ validate: magento.validate }),
    'PublicSquare_Payments/js/publicsquare_payments': magento.publicsquare,
    'Magento_Checkout/js/model/url-builder': magento.urlBuilder,
    'mage/storage': {},
    'Magento_Checkout/js/model/quote': magento.quote,
    'Magento_Checkout/js/model/payment/additional-validators': magento.additionalValidators,
    'Magento_Checkout/js/model/full-screen-loader': magento.fullScreenLoader,
    'mage/translate': (text) => text,
    'Magento_Vault/js/view/payment/vault-enabler': function VaultEnabler() {
      return vaultEnabler;
    },
    'Magento_Ui/js/model/messageList': magento.messageList,
    'Magento_Customer/js/model/customer': magento.customer,
    'Magento_Checkout/js/model/place-order': magento.placeOrder,
  });
  return new Renderer();
}

function errorMessages() {
  return magento.messageList.addErrorMessage.mock.calls.map(([{ message }]) => message);
}

describe('publicsquare_payments-method', () => {
  beforeEach(() => {
    window.checkoutConfig = checkoutConfig();
    // The renderer calls _.extend without listing underscore as a dependency; Magento provides the global.
    globalThis._ = { extend: Object.assign };
    $.mage = { redirect: vi.fn() };
    vi.spyOn(console, 'log').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete window.checkoutConfig;
    delete globalThis._;
    delete $.mage;
  });

  it('mounts the card form with the API key and the configured customization', () => {
    createRenderer().onContainerRendered();

    expect(magento.publicsquare.initElements).toHaveBeenCalledWith(
      { apiKey: 'pk_test_key', selector: '#publicsquare-elements-form', cardInputCustomization: { theme: 'dark' } },
      expect.any(Function),
    );
  });

  describe('placeOrder', () => {
    it('tokenizes the card, places a guest order, and goes to the success page', async () => {
      const renderer = createRenderer();

      await renderer.placeOrder();

      expect(magento.publicsquare.createCard).toHaveBeenCalledWith('Jane Doe', magento.publicsquare.cardElement);
      expect(magento.urlBuilder.createUrl).toHaveBeenCalledWith(GUEST_URL, { quoteId: 'quote_1' });
      expect(placeOrderRequest(magento.placeOrder).body).toEqual({
        paymentMethod: {
          method: 'publicsquare_payments',
          additional_data: { cardId: 'card_1', idempotencyKey: renderer.idempotencyKey, saveCard: false },
        },
        email: 'jane@example.com',
      });
      expect($.mage.redirect).toHaveBeenCalledWith('https://shop.test/publicsquare/success?refergues=mask_123');
    });

    it('places a logged-in order without the guest email', async () => {
      window.checkoutConfig.isCustomerLoggedIn = true;
      const renderer = createRenderer();
      magento.customer.isLoggedIn.mockReturnValue(true);

      await renderer.placeOrder();

      expect(magento.urlBuilder.createUrl).toHaveBeenCalledWith(CUSTOMER_URL, { quoteId: 'quote_1' });
      expect(placeOrderRequest(magento.placeOrder).body).not.toHaveProperty('email');
      expect($.mage.redirect).toHaveBeenCalledWith('https://shop.test/publicsquare/success?refercust=mask_123');
    });

    // A virtual-only cart has no shipping step, so the billing address must go with the order.
    it.each([
      ['sends', [{ product_type: 'virtual' }], true],
      ['does not send', [{ product_type: 'virtual' }, { product_type: 'simple' }], false],
    ])('%s the billing address for a logged-in cart with %j', async (_sends, items, expected) => {
      const renderer = createRenderer();
      magento.customer.isLoggedIn.mockReturnValue(true);
      magento.quote.getItems.mockReturnValue(items);

      await renderer.placeOrder();

      expect('billingAddress' in placeOrderRequest(magento.placeOrder).body).toBe(expected);
    });

    it('sends the checkout agreement ids and the save-card choice', async () => {
      window.checkoutConfig.checkoutAgreements = { agreements: [{ agreementId: '1' }, { agreementId: '4' }] };
      const renderer = createRenderer();
      renderer.vaultEnabler.isActivePaymentTokenEnabler.mockReturnValue(true);

      await renderer.placeOrder();

      const { paymentMethod } = placeOrderRequest(magento.placeOrder).body;
      expect(paymentMethod.extension_attributes).toEqual({ agreement_ids: ['1', '4'] });
      expect(paymentMethod.additional_data.saveCard).toBe(true);
      expect(renderer.vaultEnabler.visitAdditionalData).toHaveBeenCalledWith(paymentMethod);
    });

    it.each([
      ['the payment form', () => magento.validate.mockReturnValue(false)],
      ['another checkout step', () => magento.additionalValidators.validate.mockReturnValue(false)],
    ])('stops before tokenizing when %s is not valid', async (_where, invalidate) => {
      const renderer = createRenderer();
      invalidate();

      await expect(renderer.placeOrder()).resolves.toBe(false);

      expect(magento.publicsquare.createCard).not.toHaveBeenCalled();
      expect(errorMessages()).toEqual(['Please check your checkout details.']);
    });

    it('ignores a second click while the order is submitting', async () => {
      const renderer = createRenderer();

      await Promise.all([renderer.placeOrder(), renderer.placeOrder()]);

      expect(magento.publicsquare.createCard).toHaveBeenCalledTimes(1);
      expect(magento.placeOrder).toHaveBeenCalledTimes(1);
    });

    it('lets the shopper retry with a new idempotency key after the card fails to tokenize', async () => {
      const renderer = createRenderer();
      const firstKey = renderer.idempotencyKey;
      magento.publicsquare.createCard.mockRejectedValueOnce(new Error('card declined'));

      await renderer.placeOrder();

      expect(magento.fullScreenLoader.stopLoader).toHaveBeenCalled();
      expect(errorMessages()).toEqual([renderer.errorMessage]);
      expect(renderer.idempotencyKey).not.toBe(firstKey);
      expect(magento.placeOrder).not.toHaveBeenCalled();

      await renderer.placeOrder();
      expect(magento.placeOrder).toHaveBeenCalledTimes(1);
    });

    it.each([
      ['a plain', 'Your card was declined.', 'Your card was declined.'],
      ['a JSON-encoded', JSON.stringify({ message: 'Insufficient funds.' }), 'Insufficient funds.'],
      ['a JSON-encoded non-message', JSON.stringify({ code: 42 }), JSON.stringify({ code: 42 })],
      ['no', undefined, 'Something went wrong. Please try again or contact support for assistance.'],
    ])('shows one error when the order fails with %s server message', async (_kind, message, expected) => {
      const renderer = createRenderer();
      const firstKey = renderer.idempotencyKey;
      magento.placeOrder.mockImplementation(failedPlaceOrder({ message }));

      await renderer.placeOrder();

      expect(errorMessages()).toEqual([expected]);
      expect(renderer.idempotencyKey).not.toBe(firstKey);
      expect(magento.fullScreenLoader.stopLoader).toHaveBeenCalled();
      expect(renderer.submitting).toBe(false);
      expect($.mage.redirect).not.toHaveBeenCalled();
    });
  });
});
