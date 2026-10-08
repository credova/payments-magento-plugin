import $ from 'jquery';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { loadAmdModule } from '../helpers/amd.js';

const ADD_CARD = 'PublicSquare/Payments/view/frontend/web/js/add-card.js';

let sdk;
let psqSdk;
let modal;
let submitted;

// The parts of the My Account page that the module reads, as add-card.phtml renders them.
function renderAddCardPage() {
  document.body.innerHTML = `
    <button id="psq-cc-add-button">Add Card</button>
    <div class="psq-add-card__form psq-add-card__form--hidden">
      <input id="psq-form-cardholder-name" value="Jane Doe">
      <div id="psq-card-element"></div>
      <button class="psq-form__button--primary" type="submit">Add Payment Method</button>
      <button class="psq-form__button--cancel" type="button">Cancel</button>
      <form id="psq-save-to-vault">
        <input id="psq-new-card-id"><input id="psq-new-card-exp-year">
        <input id="psq-new-card-exp-month"><input id="psq-new-card-details">
      </form>
    </div>`;
  submitted = vi.fn((event) => event.preventDefault());
  $('#psq-save-to-vault').on('submit', submitted);
}

/** Starts the module the way x-magento-init does, with the config from add-card.phtml. */
function startAddCard(config = { publicKey: 'pk_test_key', cardInputCustomization: '{"theme":"dark"}' }) {
  const cardElement = { mount: vi.fn(), metadata: { valid: true } };
  sdk = { createCardElement: vi.fn(() => cardElement) };
  psqSdk = {
    init: vi.fn(async () => sdk),
    cards: {
      create: vi.fn(async () => ({ id: 'card_1', brand: 'visa', last4: '4242', exp_month: 4, exp_year: 2030 })),
    },
  };
  modal = vi.fn();
  loadAmdModule(ADD_CARD, { jquery: $, publicsquarejs: psqSdk, 'Magento_Ui/js/modal/alert': modal })(config);
  return cardElement;
}

/** Waits for the module's async click handlers. */
function settle() {
  return new Promise((resolve) => setTimeout(resolve, 0));
}

async function openForm() {
  $('#psq-cc-add-button').trigger('click');
  await settle();
}

async function saveCard() {
  $('.psq-form__button--primary').trigger('click');
  await settle();
}

const isFormHidden = () => $('.psq-add-card__form').hasClass('psq-add-card__form--hidden');
const modalContent = () => modal.mock.calls.map(([options]) => options.content);

describe('add-card', () => {
  beforeEach(() => {
    renderAddCardPage();
    // jQuery Validate, which the My Account page loads.
    $.fn.valid = vi.fn(() => true);
    vi.spyOn(console, 'log').mockImplementation(() => {});
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete $.fn.valid;
    document.body.innerHTML = '';
  });

  describe('the add card button', () => {
    it('shows the form and mounts the card element with the configured key and customization', async () => {
      const cardElement = startAddCard();

      await openForm();

      expect(isFormHidden()).toBe(false);
      expect(psqSdk.init).toHaveBeenCalledWith('pk_test_key');
      expect(sdk.createCardElement).toHaveBeenCalledWith({ theme: 'dark' });
      expect(cardElement.mount).toHaveBeenCalledWith('#psq-card-element');
    });

    it('uses no customization when none is configured', async () => {
      startAddCard({ publicKey: 'pk_test_key', cardInputCustomization: null });

      await openForm();

      expect(sdk.createCardElement).toHaveBeenCalledWith({});
    });

    it('hides the form on the next click and shows it again without a second mount', async () => {
      startAddCard();

      await openForm();
      await openForm();
      expect(isFormHidden()).toBe(true);

      await openForm();
      expect(isFormHidden()).toBe(false);
      expect(psqSdk.init).toHaveBeenCalledTimes(1);
    });

    it('hides the form on cancel, and the next click shows it', async () => {
      startAddCard();
      await openForm();

      $('.psq-form__button--cancel').trigger('click');
      expect(isFormHidden()).toBe(true);

      await openForm();
      expect(isFormHidden()).toBe(false);
    });
  });

  describe('saving the card', () => {
    it('tokenizes the card, fills the vault form, and submits it', async () => {
      const cardElement = startAddCard();
      await openForm();

      await saveCard();

      expect(psqSdk.cards.create).toHaveBeenCalledWith({ cardholder_name: 'Jane Doe', card: cardElement });
      expect($('#psq-new-card-id').val()).toBe('card_1');
      expect($('#psq-new-card-exp-year').val()).toBe('2030');
      expect($('#psq-new-card-exp-month').val()).toBe('4');
      expect(JSON.parse($('#psq-new-card-details').val())).toEqual({
        type: 'visa',
        maskedCC: '4242',
        expirationDate: '4/2030',
      });
      expect(submitted).toHaveBeenCalledTimes(1);
      expect(modal).not.toHaveBeenCalled();
    });

    it('shows an error and does not tokenize when the card is not valid', async () => {
      const cardElement = startAddCard();
      await openForm();
      cardElement.metadata.valid = false;

      await saveCard();

      expect(modalContent()).toEqual(['The card is invalid. Please check the card details and try again.']);
      expect(psqSdk.cards.create).not.toHaveBeenCalled();
      expect(submitted).not.toHaveBeenCalled();
    });

    it.each([
      ['the vault form is not valid', () => $.fn.valid.mockReturnValue(false), 'Form information incorrect.'],
      [
        'the card fails to tokenize',
        () => psqSdk.cards.create.mockRejectedValueOnce(new Error('declined')),
        'Unknown error creating card.',
      ],
    ])('shows an error and does not submit when %s', async (_reason, fail, message) => {
      startAddCard();
      await openForm();
      fail();

      await saveCard();

      expect(modalContent()).toEqual([message]);
      expect(submitted).not.toHaveBeenCalled();
    });
  });
});
