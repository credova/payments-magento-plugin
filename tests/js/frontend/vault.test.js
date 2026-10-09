import $ from 'jquery';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadAmdModule } from '../helpers/amd.js';
import ko from 'knockout';
import { failedPlaceOrder, placeOrderRequest, uiComponent } from '../helpers/magento.js';
import { settle } from '../helpers/settle.js';

const RENDERER = 'PublicSquare/Payments/view/frontend/web/js/view/payment/method-renderer/vault.js';
const ORDER_ERROR = 'PublicSquare/Payments/view/frontend/web/js/model/order-error.js';

let magento;

// Builds the renderer with fakes for Magento's models; `requirement` is the CVV endpoint's answer.
function createRenderer({ requirement = { required: false } } = {}) {
  const cvcHandlers = {};
  magento = {
    storage: { post: vi.fn(() => $.Deferred().resolve(requirement).promise()) },
    validators: { validate: vi.fn(() => true) },
    cvcElement: { on: vi.fn((event, handler) => (cvcHandlers[event] = handler)), unmount: vi.fn(), clear: vi.fn() },
    typeCvc: (complete) => cvcHandlers.change({ complete }),
    focusCvc: (focused) => cvcHandlers[focused ? 'focus' : 'blur'](),
    urlBuilder: { createUrl: vi.fn(() => '/rest/default/V1/payment-information') },
    quote: {
      getQuoteId: vi.fn(() => 'quote_1'),
      guestEmail: 'jane@example.com',
      paymentMethod: ko.observable(null),
      totals: ko.observable({}),
    },
    fullScreenLoader: { startLoader: vi.fn(), stopLoader: vi.fn() },
    messageList: { addErrorMessage: vi.fn() },
    customer: { isLoggedIn: vi.fn(() => false) },
    placeOrder: vi.fn(() => $.Deferred().resolve().promise()),
  };
  magento.publicsquare = {
    ensureInitialized: vi.fn(async () => ({})),
    createCvcElement: vi.fn(() => magento.cvcElement),
    updateCvc: vi.fn(async (id) => ({ id })),
  };

  const Renderer = loadAmdModule(RENDERER, {
    jquery: $,
    'Magento_Vault/js/view/payment/method-renderer/vault': uiComponent(),
    'Magento_Ui/js/model/messageList': magento.messageList,
    'Magento_Checkout/js/model/full-screen-loader': magento.fullScreenLoader,
    'Magento_Checkout/js/model/url-builder': magento.urlBuilder,
    'mage/storage': magento.storage,
    'mage/translate': (text) => text,
    'Magento_Customer/js/model/customer': magento.customer,
    'Magento_Checkout/js/model/place-order': magento.placeOrder,
    underscore: { extend: Object.assign },
    'PublicSquare_Payments/js/model/order-error': loadAmdModule(ORDER_ERROR),
    'Magento_Checkout/js/model/quote': magento.quote,
    'Magento_Checkout/js/model/payment/additional-validators': magento.validators,
    publicsquare_payments: magento.publicsquare,
  });
  // Magento passes the saved card's code and hash, and resolves hostedFields to the card renderer.
  return new Renderer({
    code: 'publicsquare_payments_cc_vault_1',
    index: 'publicsquare_payments_cc_vault_1',
    getId() {
      return this.index;
    },
    publicHash: 'hash_abc',
    hostedFields: (callback) => callback(),
    requiresCvc: ko.observable(false),
    cvcErrorMessage: ko.observable(''),
    cvcFocused: ko.observable(false),
  });
}

describe('vault', () => {
  beforeEach(() => {
    window.checkoutConfig = {
      payment: { publicsquare_payments: { successUrl: 'https://shop.test/publicsquare/success' } },
      quoteData: { entity_id: 'mask_123' },
      isCustomerLoggedIn: false,
    };
    $.mage = { redirect: vi.fn() };
  });

  afterEach(() => {
    delete window.checkoutConfig;
    delete $.mage;
  });

  it('places a guest order with the saved card and goes to the success page', () => {
    const renderer = createRenderer();

    renderer.placeOrder();

    expect(magento.urlBuilder.createUrl).toHaveBeenCalledWith('/guest-carts/:quoteId/payment-information', {
      quoteId: 'quote_1',
    });
    expect(placeOrderRequest(magento.placeOrder).body).toEqual({
      email: 'jane@example.com',
      paymentMethod: {
        method: 'publicsquare_payments_cc_vault_1',
        additional_data: { public_hash: 'hash_abc', idempotencyKey: renderer.idempotencyKey },
      },
    });
    expect($.mage.redirect).toHaveBeenCalledWith('https://shop.test/publicsquare/success?refergues=mask_123');
    expect(magento.fullScreenLoader.stopLoader).toHaveBeenCalled();
  });

  it('places a logged-in order without the guest email', () => {
    window.checkoutConfig.isCustomerLoggedIn = true;
    const renderer = createRenderer();
    magento.customer.isLoggedIn.mockReturnValue(true);

    renderer.placeOrder();

    expect(magento.urlBuilder.createUrl).toHaveBeenCalledWith('/carts/mine/payment-information', {
      quoteId: 'quote_1',
    });
    expect(placeOrderRequest(magento.placeOrder).body).not.toHaveProperty('email');
    expect($.mage.redirect).toHaveBeenCalledWith('https://shop.test/publicsquare/success?refercust=mask_123');
  });

  it('sends the checkout agreement ids', () => {
    window.checkoutConfig.checkoutAgreements = { agreements: [{ agreementId: '2' }] };
    const renderer = createRenderer();

    renderer.placeOrder();

    expect(placeOrderRequest(magento.placeOrder).body.paymentMethod.extension_attributes).toEqual({
      agreement_ids: ['2'],
    });
  });

  it.each([
    ['the server message', { message: 'Insufficient funds.' }, 'Insufficient funds.'],
    ['the default message', {}, 'Something went wrong. Please try again or contact support for assistance.'],
    [
      'the default message for a number',
      { message: 500 },
      'Something went wrong. Please try again or contact support for assistance.',
    ],
    [
      'the default message for an object',
      { message: { code: 42 } },
      'Something went wrong. Please try again or contact support for assistance.',
    ],
  ])('shows %s and stays on the page when the order fails', (_which, body, expected) => {
    const renderer = createRenderer();
    const firstKey = renderer.idempotencyKey;
    magento.placeOrder.mockImplementation(failedPlaceOrder(body));

    renderer.placeOrder();

    expect(magento.messageList.addErrorMessage).toHaveBeenCalledExactlyOnceWith({ message: expected });
    expect(renderer.idempotencyKey).not.toBe(firstKey);
    expect(magento.fullScreenLoader.stopLoader).toHaveBeenCalled();
    expect($.mage.redirect).not.toHaveBeenCalled();
  });

  // The payment may have gone through, so the retry must reuse the key for PublicSquare to dedupe it.
  it.each([
    ['times out', undefined, 0],
    ['fails on the server', { message: 'Service unavailable' }, 503],
  ])('keeps the idempotency key when the order request %s', (_how, body, status) => {
    const renderer = createRenderer();
    const firstKey = renderer.idempotencyKey;
    magento.placeOrder.mockImplementation(failedPlaceOrder(body, status));

    renderer.placeOrder();

    expect(renderer.idempotencyKey).toBe(firstKey);
    expect(magento.messageList.addErrorMessage).toHaveBeenCalledOnce();
  });

  it('does not place the order when checkout validation fails', () => {
    const renderer = createRenderer();
    magento.validators.validate.mockReturnValue(false);

    renderer.placeOrder();

    expect(magento.placeOrder).not.toHaveBeenCalled();
  });

  it('ignores a second click while the order is being placed', () => {
    const renderer = createRenderer();
    magento.placeOrder.mockReturnValue($.Deferred().promise());

    renderer.placeOrder();
    renderer.placeOrder();

    expect(magento.placeOrder).toHaveBeenCalledOnce();
  });

  describe('CVV re-entry', () => {
    beforeEach(() => {
      window.checkoutConfig.payment.publicsquare_payments.pk = 'pk_test_key';
      window.checkoutConfig.payment.publicsquare_payments.requireCvvForNewShippingAddress = true;
    });

    it('does not call the server when the setting is off', async () => {
      window.checkoutConfig.payment.publicsquare_payments.requireCvvForNewShippingAddress = false;
      const renderer = createRenderer();

      await expect(renderer.checkCvvRequirement()).resolves.toBe(false);
      expect(magento.storage.post).not.toHaveBeenCalled();
    });

    it('asks the server about the selected card', async () => {
      const renderer = createRenderer();

      await renderer.checkCvvRequirement();

      expect(magento.storage.post).toHaveBeenCalledWith(
        '/rest/default/V1/payment-information',
        JSON.stringify({ publicHash: 'hash_abc' }),
        false,
      );
      expect(magento.urlBuilder.createUrl).toHaveBeenCalledWith('/publicsquare/carts/mine/cvv-requirement', {});
    });

    it('checks the requirement when the saved card is selected', () => {
      const renderer = createRenderer();

      renderer.selectPaymentMethod();

      expect(magento.storage.post).toHaveBeenCalled();
    });

    it('shows a CVV field sized for the card when the server requires it', async () => {
      const renderer = createRenderer({ requirement: { required: true, card_id: 'card_1', card_brand: 'amex' } });

      await expect(renderer.checkCvvRequirement()).resolves.toBe(true);

      expect(renderer.requiresCvc()).toBe(true);
      expect(magento.publicsquare.ensureInitialized).toHaveBeenCalledWith('pk_test_key');
      expect(magento.publicsquare.createCvcElement).toHaveBeenCalledWith(
        '#psq-cvc-publicsquare_payments_cc_vault_1',
        'amex',
      );
    });

    it('marks the CVV field focused while the shopper types in it', async () => {
      const renderer = createRenderer({ requirement: { required: true, card_id: 'card_1', card_brand: 'visa' } });
      await renderer.checkCvvRequirement();

      magento.focusCvc(true);
      expect(renderer.cvcFocused()).toBe(true);

      magento.focusCvc(false);
      expect(renderer.cvcFocused()).toBe(false);
    });

    it('shows no CVV field when the server does not require it', async () => {
      const renderer = createRenderer();

      await expect(renderer.checkCvvRequirement()).resolves.toBe(false);

      expect(renderer.requiresCvc()).toBe(false);
      expect(magento.publicsquare.createCvcElement).not.toHaveBeenCalled();
    });

    it('asks for the CVV instead of placing the order when it is missing', async () => {
      const renderer = createRenderer({ requirement: { required: true, card_id: 'card_1' } });
      await renderer.checkCvvRequirement();

      renderer.placeOrder();
      await settle();

      expect(renderer.cvcErrorMessage()).toBe("Enter the card's security code.");
      expect(magento.publicsquare.updateCvc).not.toHaveBeenCalled();
      expect(magento.placeOrder).not.toHaveBeenCalled();
    });

    it('attaches the CVV to the saved card, then places the order', async () => {
      const renderer = createRenderer({ requirement: { required: true, card_id: 'card_1' } });
      await renderer.checkCvvRequirement();
      magento.typeCvc(true);

      renderer.placeOrder();
      await settle();

      expect(magento.publicsquare.updateCvc).toHaveBeenCalledWith('card_1', magento.cvcElement);
      expect(magento.placeOrder).toHaveBeenCalledOnce();
    });

    it('waits for a CVV check that is still running before placing the order', async () => {
      const renderer = createRenderer();
      const answer = $.Deferred();
      magento.storage.post.mockReturnValue(answer.promise());
      renderer.checkCvvRequirement();

      renderer.placeOrder();
      await settle();
      expect(magento.placeOrder).not.toHaveBeenCalled();

      answer.resolve({ required: true, card_id: 'card_1' });
      await settle();

      expect(renderer.cvcErrorMessage()).toBe("Enter the card's security code.");
      expect(magento.placeOrder).not.toHaveBeenCalled();
      expect(magento.fullScreenLoader.startLoader).toHaveBeenCalledTimes(
        magento.fullScreenLoader.stopLoader.mock.calls.length,
      );
    });

    it('places the order once a running check says no CVV is needed', async () => {
      const renderer = createRenderer();
      const answer = $.Deferred();
      magento.storage.post.mockReturnValue(answer.promise());
      renderer.checkCvvRequirement();

      renderer.placeOrder();
      answer.resolve({ required: false });
      await settle();

      expect(magento.placeOrder).toHaveBeenCalledOnce();
    });

    it('does not place the order when the CVV cannot be saved', async () => {
      const renderer = createRenderer({ requirement: { required: true, card_id: 'card_1' } });
      await renderer.checkCvvRequirement();
      magento.typeCvc(true);
      magento.publicsquare.updateCvc.mockResolvedValue({ error: { error: 'session expired' } });

      renderer.placeOrder();
      await settle();

      expect(renderer.cvcErrorMessage()).toBe("We couldn't save the security code. Please check it and try again.");
      expect(magento.placeOrder).not.toHaveBeenCalled();
    });

    it.each([
      ['the CVV is missing', false],
      ['the CVV is attached and the order fails', true],
    ])('stops the loader as often as it starts it when %s', async (_case, typed) => {
      const renderer = createRenderer({ requirement: { required: true, card_id: 'card_1' } });
      await renderer.checkCvvRequirement();
      if (typed) magento.typeCvc(true);
      magento.placeOrder.mockImplementation(failedPlaceOrder({ message: 'Declined.' }));

      renderer.placeOrder();
      await settle();

      const loader = magento.fullScreenLoader;
      expect(loader.startLoader.mock.calls.length).toBe(loader.stopLoader.mock.calls.length);
    });
  });

  describe('backing out of the CVV step', () => {
    beforeEach(() => {
      window.checkoutConfig.payment.publicsquare_payments.pk = 'pk_test_key';
      window.checkoutConfig.payment.publicsquare_payments.requireCvvForNewShippingAddress = true;
    });

    async function selectedWithCvvShown(requirement = { required: true, card_id: 'card_1' }) {
      const renderer = createRenderer({ requirement });
      magento.quote.paymentMethod({ method: 'publicsquare_payments_cc_vault_1' });
      await renderer.checkCvvRequirement();
      return renderer;
    }

    it('removes the CVV field when another payment method is chosen', async () => {
      const renderer = await selectedWithCvvShown();

      magento.quote.paymentMethod({ method: 'publicsquare_payments' });

      expect(magento.cvcElement.unmount).toHaveBeenCalled();
      expect(renderer.requiresCvc()).toBe(false);
    });

    it('re-checks when the cart changes while the card is selected', async () => {
      await selectedWithCvvShown();
      magento.storage.post.mockClear();

      magento.quote.totals({ grand_total: 50 });

      expect(magento.storage.post).toHaveBeenCalledOnce();
    });

    it('hides the CVV field when the new address no longer needs it', async () => {
      const renderer = await selectedWithCvvShown();
      magento.storage.post.mockReturnValue($.Deferred().resolve({ required: false }).promise());

      magento.quote.totals({ grand_total: 50 });
      await settle();

      expect(magento.cvcElement.unmount).toHaveBeenCalled();
      expect(renderer.requiresCvc()).toBe(false);
    });

    it('does not re-check for a card that is not selected', () => {
      createRenderer();
      magento.storage.post.mockClear();

      magento.quote.totals({ grand_total: 50 });

      expect(magento.storage.post).not.toHaveBeenCalled();
    });

    it('mounts one CVV field when two checks finish together', async () => {
      const renderer = createRenderer({ requirement: { required: true, card_id: 'card_1' } });

      await Promise.all([renderer.checkCvvRequirement(), renderer.checkCvvRequirement()]);

      expect(magento.publicsquare.createCvcElement).toHaveBeenCalledOnce();
    });

    it('ignores an older answer that arrives after a newer one', async () => {
      const renderer = createRenderer();
      magento.quote.paymentMethod({ method: 'publicsquare_payments_cc_vault_1' });
      const older = $.Deferred();
      const newer = $.Deferred();
      magento.storage.post.mockReturnValueOnce(older.promise()).mockReturnValueOnce(newer.promise());
      renderer.checkCvvRequirement();
      renderer.checkCvvRequirement();

      newer.resolve({ required: false });
      older.resolve({ required: true, card_id: 'card_1' });
      await settle();

      expect(renderer.requiresCvc()).toBe(false);
      expect(magento.publicsquare.createCvcElement).not.toHaveBeenCalled();
    });

    it('ignores an answer that arrives after another payment method is chosen', async () => {
      const renderer = createRenderer();
      magento.quote.paymentMethod({ method: 'publicsquare_payments_cc_vault_1' });
      const answer = $.Deferred();
      magento.storage.post.mockReturnValue(answer.promise());
      renderer.checkCvvRequirement();

      magento.quote.paymentMethod({ method: 'checkmo' });
      answer.resolve({ required: true, card_id: 'card_1' });
      await settle();

      expect(renderer.requiresCvc()).toBe(false);
      expect(magento.publicsquare.createCvcElement).not.toHaveBeenCalled();
    });

    it('after a failed order, empties the CVV field and checks again', async () => {
      const renderer = await selectedWithCvvShown();
      magento.typeCvc(true);
      magento.placeOrder.mockImplementation(failedPlaceOrder({ message: "The security code didn't match." }));
      magento.storage.post.mockClear();

      renderer.placeOrder();
      await settle();

      expect(magento.messageList.addErrorMessage).toHaveBeenCalledExactlyOnceWith({
        message: "The security code didn't match.",
      });
      expect(magento.cvcElement.clear).toHaveBeenCalled();
      expect(magento.storage.post).toHaveBeenCalledOnce();
    });

    it('after a failed order, checks again even with the setting off', async () => {
      window.checkoutConfig.payment.publicsquare_payments.requireCvvForNewShippingAddress = false;
      const renderer = createRenderer({ requirement: { required: true, card_id: 'card_1' } });
      magento.placeOrder.mockImplementation(
        failedPlaceOrder({ message: "Please re-enter your card's security code to continue." }),
      );

      renderer.placeOrder();
      await settle();

      // PublicSquare's rule rejected the payment, so the server now asks for this card's CVV.
      expect(magento.storage.post).toHaveBeenCalledOnce();
      expect(renderer.requiresCvc()).toBe(true);
    });

    it('can place the order again after a failure', async () => {
      const renderer = createRenderer();
      magento.placeOrder.mockImplementationOnce(failedPlaceOrder({ message: 'Declined.' }));

      renderer.placeOrder();
      await settle();
      renderer.placeOrder();

      expect(magento.placeOrder).toHaveBeenCalledTimes(2);
    });
  });
});
