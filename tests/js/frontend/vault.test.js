import $ from 'jquery';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadAmdModule } from '../helpers/amd.js';
import { failedPlaceOrder, placeOrderRequest, uiComponent } from '../helpers/magento.js';

const RENDERER = 'PublicSquare/Payments/view/frontend/web/js/view/payment/method-renderer/vault.js';

let magento;

/** Builds the saved-card renderer with fakes for each Magento model it uses. */
function createRenderer() {
  magento = {
    urlBuilder: { createUrl: vi.fn(() => '/rest/default/V1/payment-information') },
    quote: { getQuoteId: vi.fn(() => 'quote_1'), guestEmail: 'jane@example.com' },
    fullScreenLoader: { startLoader: vi.fn(), stopLoader: vi.fn() },
    messageList: { addErrorMessage: vi.fn() },
    customer: { isLoggedIn: vi.fn(() => false) },
    placeOrder: vi.fn(() => $.Deferred().resolve().promise()),
  };

  const Renderer = loadAmdModule(RENDERER, {
    jquery: $,
    'Magento_Vault/js/view/payment/method-renderer/vault': uiComponent(),
    'Magento_Ui/js/model/messageList': magento.messageList,
    'Magento_Checkout/js/model/full-screen-loader': magento.fullScreenLoader,
    'Magento_Checkout/js/model/url-builder': magento.urlBuilder,
    'mage/storage': {},
    'mage/translate': (text) => text,
    'Magento_Customer/js/model/customer': magento.customer,
    'Magento_Checkout/js/model/place-order': magento.placeOrder,
    'Magento_Checkout/js/model/quote': magento.quote,
  });
  // Magento passes the saved card's code and hash, and resolves hostedFields to the card renderer.
  return new Renderer({
    code: 'publicsquare_payments_cc_vault_1',
    publicHash: 'hash_abc',
    hostedFields: (callback) => callback(),
  });
}

describe('vault', () => {
  beforeEach(() => {
    window.checkoutConfig = {
      payment: { publicsquare_payments: { successUrl: 'https://shop.test/publicsquare/success' } },
      quoteData: { entity_id: 'mask_123' },
      isCustomerLoggedIn: false,
    };
    // The renderer calls _.extend without listing underscore as a dependency; Magento provides the global.
    globalThis._ = { extend: Object.assign };
    $.mage = { redirect: vi.fn() };
  });

  afterEach(() => {
    delete window.checkoutConfig;
    delete globalThis._;
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

  it('shows an error and stays on the page when the order fails', () => {
    const renderer = createRenderer();
    const firstKey = renderer.idempotencyKey;
    magento.placeOrder.mockImplementation(failedPlaceOrder({ message: 'declined' }));

    renderer.placeOrder();

    expect(magento.messageList.addErrorMessage).toHaveBeenCalledExactlyOnceWith({
      message: 'Something went wrong. Please try again or contact support for assistance.',
    });
    expect(renderer.idempotencyKey).not.toBe(firstKey);
    expect(magento.fullScreenLoader.stopLoader).toHaveBeenCalled();
    expect($.mage.redirect).not.toHaveBeenCalled();
  });
});
