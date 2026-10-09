import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadAmdModule } from '../helpers/amd.js';
import { deferred, instantiate, observable } from '../helpers/magento.js';

const RENDERER = 'PublicSquare/Payments/view/frontend/web/js/view/payment/method-renderer/vault.js';

function setup({ enabled = true, requirement = { required: false } } = {}) {
  window.checkoutConfig = {
    payment: {
      publicsquare_payments: {
        pk: 'pk_test_key',
        requireCvvForNewShippingAddress: enabled,
        successUrl: 'https://magento.test/checkout/onepage/success/',
      },
    },
    quoteData: { entity_id: '1' },
    isCustomerLoggedIn: true,
  };
  window._ = { extend: Object.assign };

  const cvcHandlers = {};
  const cvcElement = {
    on: vi.fn((event, handler) => (cvcHandlers[event] = handler)),
    unmount: vi.fn(),
  };
  const deps = {
    jquery: { mage: { redirect: vi.fn() } },
    'Magento_Vault/js/view/payment/method-renderer/vault': { extend: (spec) => spec },
    'Magento_Ui/js/model/messageList': { addErrorMessage: vi.fn() },
    'Magento_Checkout/js/model/full-screen-loader': { startLoader: vi.fn(), stopLoader: vi.fn() },
    'Magento_Checkout/js/model/url-builder': { createUrl: vi.fn((url) => `/rest/default/V1${url}`) },
    'mage/storage': { post: vi.fn(() => deferred(requirement)) },
    'mage/translate': (text) => text,
    'Magento_Customer/js/model/customer': { isLoggedIn: () => true },
    'Magento_Checkout/js/model/place-order': vi.fn(() => deferred({})),
    'Magento_Checkout/js/model/quote': { getQuoteId: () => '1' },
    'Magento_Checkout/js/model/payment/additional-validators': { validate: vi.fn(() => true) },
    publicsquare_payments: {
      ensureInitialized: vi.fn(() => Promise.resolve({})),
      createCvcElement: vi.fn(() => cvcElement),
      updateCvc: vi.fn(() => Promise.resolve({ id: 'card_saved123' })),
    },
  };
  const spec = loadAmdModule(RENDERER, deps);
  const renderer = instantiate(spec, {
    code: 'publicsquare_payments_cc_vault',
    index: 'publicsquare_payments_cc_vault_1',
    publicHash: 'hash123',
    getId() {
      return this.index;
    },
    hostedFields: (callback) => callback(),
    requiresCvc: observable(false),
    cvcErrorMessage: observable(''),
  });
  return { renderer, deps, cvcElement, typeCvc: (complete) => cvcHandlers.change({ complete }) };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('saved-card renderer: CVV re-entry', () => {
  beforeEach(() => {
    vi.useRealTimers();
  });

  afterEach(() => {
    delete window.checkoutConfig;
    delete window._;
  });

  it('does not call the server when the setting is off', async () => {
    const { renderer, deps } = setup({ enabled: false });

    await expect(renderer.checkCvvRequirement()).resolves.toBe(false);
    expect(deps['mage/storage'].post).not.toHaveBeenCalled();
  });

  it('asks the server about the selected card', async () => {
    const { renderer, deps } = setup();

    await renderer.checkCvvRequirement();

    expect(deps['mage/storage'].post).toHaveBeenCalledWith(
      '/rest/default/V1/publicsquare/carts/mine/cvv-requirement',
      JSON.stringify({ publicHash: 'hash123' }),
      false,
    );
  });

  it('shows a CVV field sized for the card when the server requires it', async () => {
    const { renderer, deps } = setup({
      requirement: { required: true, card_id: 'card_saved123', card_brand: 'amex' },
    });

    await expect(renderer.checkCvvRequirement()).resolves.toBe(true);

    expect(renderer.requiresCvc()).toBe(true);
    expect(deps.publicsquare_payments.ensureInitialized).toHaveBeenCalledWith('pk_test_key');
    expect(deps.publicsquare_payments.createCvcElement).toHaveBeenCalledWith(
      '#psq-cvc-publicsquare_payments_cc_vault_1',
      'amex',
    );
  });

  it('shows no CVV field when the server does not require it', async () => {
    const { renderer, deps } = setup({ requirement: { required: false } });

    await expect(renderer.checkCvvRequirement()).resolves.toBe(false);

    expect(renderer.requiresCvc()).toBe(false);
    expect(deps.publicsquare_payments.createCvcElement).not.toHaveBeenCalled();
  });

  it('checks the requirement when the saved card is selected', async () => {
    const { renderer, deps } = setup();

    renderer.selectPaymentMethod();

    expect(renderer._super).toHaveBeenCalled();
    expect(deps['mage/storage'].post).toHaveBeenCalled();
  });

  it('places the order as before when no CVV is required', async () => {
    const { renderer, deps } = setup();

    renderer.placeOrder();
    await flush();

    expect(deps.publicsquare_payments.updateCvc).not.toHaveBeenCalled();
    expect(deps['Magento_Checkout/js/model/place-order']).toHaveBeenCalled();
  });

  it('asks for the CVV instead of placing the order when it is missing', async () => {
    const { renderer, deps } = setup({ requirement: { required: true, card_id: 'card_saved123' } });
    await renderer.checkCvvRequirement();

    renderer.placeOrder();
    await flush();

    expect(renderer.cvcErrorMessage()).toBe("Enter the card's security code.");
    expect(deps.publicsquare_payments.updateCvc).not.toHaveBeenCalled();
    expect(deps['Magento_Checkout/js/model/place-order']).not.toHaveBeenCalled();
    expect(deps['Magento_Checkout/js/model/full-screen-loader'].stopLoader).toHaveBeenCalled();
  });

  it('attaches the CVV to the saved card, then places the order', async () => {
    const { renderer, deps, cvcElement, typeCvc } = setup({
      requirement: { required: true, card_id: 'card_saved123' },
    });
    await renderer.checkCvvRequirement();
    typeCvc(true);

    renderer.placeOrder();
    await flush();

    expect(deps.publicsquare_payments.updateCvc).toHaveBeenCalledWith('card_saved123', cvcElement);
    expect(deps['Magento_Checkout/js/model/place-order']).toHaveBeenCalled();
  });

  it('does not place the order when the CVV cannot be saved', async () => {
    const { renderer, deps, typeCvc } = setup({ requirement: { required: true, card_id: 'card_saved123' } });
    await renderer.checkCvvRequirement();
    typeCvc(true);
    deps.publicsquare_payments.updateCvc.mockResolvedValue({ error: { error: 'session expired' } });

    renderer.placeOrder();
    await flush();

    expect(renderer.cvcErrorMessage()).toBe("We couldn't save the security code. Please check it and try again.");
    expect(deps['Magento_Checkout/js/model/place-order']).not.toHaveBeenCalled();
  });

  it('does not place the order when checkout validation fails', async () => {
    const { renderer, deps } = setup();
    deps['Magento_Checkout/js/model/payment/additional-validators'].validate.mockReturnValue(false);

    renderer.placeOrder();
    await flush();

    expect(deps['Magento_Checkout/js/model/place-order']).not.toHaveBeenCalled();
  });

  it.each([
    ['no CVV required', {}, () => {}],
    ['CVV missing', { requirement: { required: true, card_id: 'card_saved123' } }, () => {}],
    ['CVV attached', { requirement: { required: true, card_id: 'card_saved123' } }, (ctx) => ctx.typeCvc(true)],
  ])('stops the loader as often as it starts it (%s)', async (_name, options, prepare) => {
    const ctx = setup(options);
    await ctx.renderer.checkCvvRequirement();
    prepare(ctx);
    ctx.deps['Magento_Checkout/js/model/place-order'].mockReturnValue(deferred({}, false));

    ctx.renderer.placeOrder();
    await flush();

    const loader = ctx.deps['Magento_Checkout/js/model/full-screen-loader'];
    expect(loader.startLoader.mock.calls.length).toBe(loader.stopLoader.mock.calls.length);
  });
});
