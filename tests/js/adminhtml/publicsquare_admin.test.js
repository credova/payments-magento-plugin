import $ from 'jquery';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadAmdModule } from '../helpers/amd.js';

const ADMIN = 'PublicSquare/Payments/view/adminhtml/web/js/publicsquare_admin.js';

// The parts of the admin order-create page that the module reads, as form/cc.phtml renders them.
const PAYMENT_FIELDSET = `
  <fieldset class="admin__fieldset payment-method" id="payment_form_publicsquare_payments" style="display:none">
    <div id="publicsquare-elements-form"></div>
    <input type="hidden" id="publicsquare_payments_payment_method_nonce">
  </fieldset>`;

let publicsquare;
let originalSubmit;
let formEvents;

function renderOrderCreatePage() {
  document.body.innerHTML = `
    <form id="edit_form">
      <input id="order-billing_address_firstname" value="Jane">
      <input id="order-billing_address_lastname" value="Doe">
      <div id="order-billing_method_form">${PAYMENT_FIELDSET}</div>
    </form>`;
  formEvents = [];
  $('#edit_form').on('processStart processStop', (event) => formEvents.push(event.type));
}

/** Shows the payment fieldset, as Magento does when the admin picks a payment method. */
function showPaymentForm() {
  document.querySelector('#payment_form_publicsquare_payments').style.display = '';
}

function loadAdmin() {
  publicsquare = {
    loading: false,
    cardElement: null,
    initElements: vi.fn((params, callback) => {
      // The SDK mounts an iframe in the selector.
      document.querySelector(params.selector).append(document.createElement('iframe'));
      publicsquare.cardElement = { metadata: { valid: true } };
      callback();
    }),
    createCard: vi.fn(async () => ({ id: 'card_1' })),
  };
  return loadAmdModule(ADMIN, { jquery: $, publicsquare_payments: publicsquare });
}

/** Waits for the MutationObserver callbacks and the module's async submit. */
function settle() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

describe('publicsquare_admin', () => {
  beforeEach(() => {
    renderOrderCreatePage();
    originalSubmit = vi.fn();
    window.order = { paymentMethod: 'publicsquare_payments', submit: originalSubmit };
    vi.stubGlobal('requestAnimationFrame', (callback) => callback());
    // jQuery Validate, which the admin page loads.
    $.fn.valid = vi.fn(() => true);
    vi.spyOn(window, 'alert').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    delete window.order;
    delete $.fn.valid;
    document.body.innerHTML = '';
  });

  describe('mounting the card form', () => {
    it('mounts the card form with the configured key', () => {
      loadAdmin().init({ pk: 'pk_admin' });

      expect(publicsquare.initElements).toHaveBeenCalledExactlyOnceWith(
        { apiKey: 'pk_admin', selector: '#publicsquare-elements-form' },
        expect.any(Function),
      );
    });

    it('does not mount again when the admin shows a card form that has content', async () => {
      loadAdmin().init({ pk: 'pk_admin' });

      showPaymentForm();
      await settle();

      expect(publicsquare.initElements).toHaveBeenCalledTimes(1);
    });

    it('mounts again after Magento reloads the payment methods block', async () => {
      loadAdmin().init({ pk: 'pk_admin' });

      document.querySelector('#order-billing_method_form').innerHTML = PAYMENT_FIELDSET;
      await settle();
      showPaymentForm();
      await settle();

      expect(publicsquare.initElements).toHaveBeenCalledTimes(2);
      expect(document.querySelector('#publicsquare-elements-form iframe')).not.toBeNull();
    });
  });

  describe('submitting the order', () => {
    it('uses its own submit only while PublicSquare is the payment method', async () => {
      loadAdmin().init({ pk: 'pk_admin' });
      expect(window.order.submit).not.toBe(originalSubmit);

      window.order.paymentMethod = 'checkmo';
      showPaymentForm();
      await settle();

      expect(window.order.submit).toBe(originalSubmit);
    });

    it('tokenizes the card with the billing name, sets the nonce, and submits the order', async () => {
      loadAdmin().init({ pk: 'pk_admin' });

      window.order.submit();
      await settle();

      expect(publicsquare.createCard).toHaveBeenCalledWith('Jane Doe', publicsquare.cardElement);
      expect($('#publicsquare_payments_payment_method_nonce').val()).toBe('card_1');
      expect(originalSubmit).toHaveBeenCalledTimes(1);
      expect(formEvents).toEqual(['processStart']);
    });

    it.each([
      [
        'there is no billing name',
        () => $('#order-billing_address_firstname, #order-billing_address_lastname').val(''),
        'Cardholder name is required',
      ],
      [
        'the card is not valid',
        () => (publicsquare.cardElement.metadata.valid = false),
        'The card is invalid. Please check the card details and try again.',
      ],
    ])('alerts and does not submit when %s', async (_reason, breakForm, message) => {
      loadAdmin().init({ pk: 'pk_admin' });
      breakForm();

      window.order.submit();
      await settle();

      expect(window.alert).toHaveBeenCalledWith(message);
      expect(publicsquare.createCard).not.toHaveBeenCalled();
      expect(originalSubmit).not.toHaveBeenCalled();
      expect(formEvents).toEqual(['processStart', 'processStop']);
    });

    it.each([
      ['the card fails to tokenize', () => publicsquare.createCard.mockRejectedValueOnce(new Error('declined'))],
      ['the order form is not valid', () => $.fn.valid.mockReturnValue(false)],
    ])('stops the spinner and does not submit when %s', async (_reason, fail) => {
      loadAdmin().init({ pk: 'pk_admin' });
      fail();

      window.order.submit();
      await settle();

      expect(originalSubmit).not.toHaveBeenCalled();
      expect(formEvents).toEqual(['processStart', 'processStop']);
    });
  });
});
