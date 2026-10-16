import { afterEach, describe, expect, it, vi } from 'vitest';
import { loadAmdModule } from '../helpers/amd.js';

const PAYMENTS = 'PublicSquare/Payments/view/base/web/js/publicsquare_payments.js';

/** A fake loader whose init resolves to an SDK that makes a new card element on each call. */
function fakeSdkLoader() {
  const elements = [];
  const sdk = {
    createCardElement: vi.fn(() => {
      const element = { mount: vi.fn(), unmount: vi.fn() };
      elements.push(element);
      return element;
    }),
    cards: { create: vi.fn(async ({ cardholder_name }) => ({ id: 'card_1', cardholder_name })) },
  };
  const loader = { init: vi.fn(async () => sdk) };
  return { loader, sdk, elements };
}

function loadPayments(loader) {
  return loadAmdModule(PAYMENTS, { publicsquarejs: loader });
}

const params = { apiKey: 'pk_test_key', selector: '#publicsquare-elements-form', cardInputCustomization: { a: 1 } };

describe('publicsquare_payments', () => {
  afterEach(() => {
    delete window.publicsquare;
  });

  describe('initElements', () => {
    it('initializes the SDK and mounts a card element, then calls back', async () => {
      const { loader, sdk, elements } = fakeSdkLoader();
      const payments = loadPayments(loader);
      const callback = vi.fn();

      await payments.initElements(params, callback);

      expect(loader.init).toHaveBeenCalledWith('pk_test_key');
      expect(sdk.createCardElement).toHaveBeenCalledWith({ a: 1 });
      expect(elements[0].mount).toHaveBeenCalledWith('#publicsquare-elements-form');
      expect(payments.cardElement).toBe(elements[0]);
      expect(callback).toHaveBeenCalledWith(payments);
    });

    it('remounts a new card element without initializing the SDK again', async () => {
      const { loader, elements } = fakeSdkLoader();
      const payments = loadPayments(loader);

      await payments.initElements(params);
      await payments.initElements(params);

      expect(loader.init).toHaveBeenCalledTimes(1);
      expect(elements[0].unmount).toHaveBeenCalled();
      expect(elements[1].mount).toHaveBeenCalledWith('#publicsquare-elements-form');
      expect(payments.cardElement).toBe(elements[1]);
    });

    it('makes a call during the SDK load wait for the mounted card element', async () => {
      const { loader, sdk, elements } = fakeSdkLoader();
      const { promise: sdkLoaded, resolve: finishLoad } = Promise.withResolvers();
      loader.init.mockReturnValueOnce(sdkLoaded);
      const payments = loadPayments(loader);
      const cardElementInCallback = [];
      const callback = (module) => cardElementInCallback.push(module.cardElement);

      const first = payments.initElements(params, callback);
      const second = payments.initElements(params, callback);
      await Promise.resolve();
      expect(cardElementInCallback).toEqual([]);

      finishLoad(sdk);
      await Promise.all([first, second]);

      expect(loader.init).toHaveBeenCalledTimes(1);
      expect(elements).toHaveLength(1);
      expect(cardElementInCallback).toEqual([elements[0], elements[0]]);
    });

    it('can try again after the SDK fails to initialize', async () => {
      const { loader, elements } = fakeSdkLoader();
      loader.init.mockRejectedValueOnce(new Error('SDK load timed out'));
      const payments = loadPayments(loader);

      await expect(payments.initElements(params)).rejects.toThrow('SDK load timed out');
      await payments.initElements(params);

      expect(loader.init).toHaveBeenCalledTimes(2);
      expect(elements[0].mount).toHaveBeenCalledWith('#publicsquare-elements-form');
    });

    it.each([
      [
        'the card element cannot be created',
        (sdk) =>
          sdk.createCardElement.mockImplementationOnce(() => {
            throw new Error('bad customization');
          }),
      ],
      [
        'the card element cannot mount',
        (sdk) =>
          sdk.createCardElement.mockImplementationOnce(() => ({
            mount: () => {
              throw new Error('selector not found');
            },
            unmount: vi.fn(),
          })),
      ],
    ])('mounts the card form on the next call after %s', async (_reason, breakSetup) => {
      const { loader, sdk, elements } = fakeSdkLoader();
      breakSetup(sdk);
      const payments = loadPayments(loader);
      const callback = vi.fn();

      await expect(payments.initElements(params)).rejects.toThrow();
      await payments.initElements(params, callback);

      expect(loader.init).toHaveBeenCalledTimes(1);
      expect(elements.at(-1).mount).toHaveBeenCalledWith('#publicsquare-elements-form');
      expect(payments.cardElement).toBe(elements.at(-1));
      expect(callback).toHaveBeenCalledWith(payments);
    });
  });

  describe('createCard', () => {
    it('rejects before the SDK is initialized', async () => {
      const { loader } = fakeSdkLoader();

      await expect(loadPayments(loader).createCard('Jane Doe', {})).rejects.toThrow('PublicSquare not initialized yet');
    });

    it('tokenizes the card element with the cardholder name', async () => {
      const { loader, sdk } = fakeSdkLoader();
      const payments = loadPayments(loader);
      await payments.initElements(params);

      await expect(payments.createCard('Jane Doe', payments.cardElement)).resolves.toEqual({
        id: 'card_1',
        cardholder_name: 'Jane Doe',
      });
      expect(sdk.cards.create).toHaveBeenCalledWith({ cardholder_name: 'Jane Doe', card: payments.cardElement });
    });

    it('rejects a second tokenize while the first is in flight', async () => {
      const { loader } = fakeSdkLoader();
      const payments = loadPayments(loader);
      await payments.initElements(params);

      const first = payments.createCard('Jane Doe', payments.cardElement);

      await expect(payments.createCard('Jane Doe', payments.cardElement)).rejects.toThrow(
        'PublicSquare is still loading',
      );
      await first;
    });

    it('can tokenize again after a tokenize fails', async () => {
      const { loader, sdk } = fakeSdkLoader();
      sdk.cards.create.mockRejectedValueOnce(new Error('card declined'));
      const payments = loadPayments(loader);
      await payments.initElements(params);

      await expect(payments.createCard('Jane Doe', payments.cardElement)).rejects.toThrow('card declined');

      await expect(payments.createCard('Jane Doe', payments.cardElement)).resolves.toMatchObject({ id: 'card_1' });
    });
  });

  describe('ensureInitialized', () => {
    it('initializes the SDK once without mounting a card element', async () => {
      const { loader, sdk } = fakeSdkLoader();
      const payments = loadPayments(loader);

      await expect(payments.ensureInitialized('pk_test_key')).resolves.toBe(sdk);
      await payments.ensureInitialized('pk_test_key');

      expect(loader.init).toHaveBeenCalledTimes(1);
      expect(sdk.createCardElement).not.toHaveBeenCalled();
    });

    it('shares one SDK load with the card form when both start together', async () => {
      const { loader, sdk, elements } = fakeSdkLoader();
      const { promise: sdkLoaded, resolve: finishLoad } = Promise.withResolvers();
      loader.init.mockReturnValueOnce(sdkLoaded);
      const payments = loadPayments(loader);

      const cvvField = payments.ensureInitialized('pk_test_key');
      const cardForm = payments.initElements(params);
      finishLoad(sdk);
      await Promise.all([cvvField, cardForm]);

      expect(loader.init).toHaveBeenCalledTimes(1);
      expect(elements[0].mount).toHaveBeenCalledWith('#publicsquare-elements-form');
    });

    it('can try again after the SDK fails to initialize', async () => {
      const { loader, sdk } = fakeSdkLoader();
      loader.init.mockRejectedValueOnce(new Error('SDK load timed out'));
      const payments = loadPayments(loader);

      await expect(payments.ensureInitialized('pk_test_key')).rejects.toThrow('SDK load timed out');
      await expect(payments.ensureInitialized('pk_test_key')).resolves.toBe(sdk);
    });
  });

  describe('saved-card CVV', () => {
    async function ready() {
      const fake = fakeSdkLoader();
      const cvcElement = { mount: vi.fn() };
      fake.sdk.createElement = vi.fn(() => cvcElement);
      fake.sdk.cards.updateCvc = vi.fn(async (id) => ({ id, modified_at: '2026-10-12T15:00:00Z' }));
      const payments = loadPayments(fake.loader);
      await payments.ensureInitialized('pk_test_key');
      return { ...fake, payments, cvcElement };
    }

    it.each([
      ['visa', 'visa'],
      ['amex', 'american-express'],
      ['american_express', 'american-express'],
      ['Diners Club', 'diners-club'],
    ])('mounts a CVV element sized for a %s card', async (brand, elementBrand) => {
      const { payments, sdk, cvcElement } = await ready();

      expect(payments.createCvcElement('#psq-cvc-1', brand)).toBe(cvcElement);

      expect(sdk.createElement).toHaveBeenCalledWith('cardVerificationCode', { cardBrand: elementBrand });
      expect(cvcElement.mount).toHaveBeenCalledWith('#psq-cvc-1');
    });

    it('leaves the CVV length open for an unknown brand', async () => {
      const { payments, sdk } = await ready();

      payments.createCvcElement('#psq-cvc-1', 'something-new');

      expect(sdk.createElement).toHaveBeenCalledWith('cardVerificationCode', {});
    });

    it("gives the CVV input the theme's text style from its container", async () => {
      const { payments, sdk } = await ready();
      const container = document.createElement('div');
      container.id = 'psq-cvc-1';
      container.style.cssText = 'color: rgb(51, 51, 51); font-family: Arial, sans-serif; font-size: 14px;';
      document.body.appendChild(container);

      try {
        payments.createCvcElement('#psq-cvc-1', 'visa');
      } finally {
        container.remove();
      }

      expect(sdk.createElement).toHaveBeenCalledWith('cardVerificationCode', {
        cardBrand: 'visa',
        style: { base: { color: 'rgb(51, 51, 51)', fontFamily: 'Arial, sans-serif', fontSize: '14px' } },
      });
    });

    it('attaches the CVV to the saved card', async () => {
      const { payments, sdk, cvcElement } = await ready();

      await expect(payments.updateCvc('card_123', cvcElement)).resolves.toMatchObject({ id: 'card_123' });
      expect(sdk.cards.updateCvc).toHaveBeenCalledWith('card_123', cvcElement);
    });

    it('returns an error instead of throwing when the CVV update fails', async () => {
      const { payments, sdk, cvcElement } = await ready();
      sdk.cards.updateCvc.mockRejectedValue(new Error('session expired'));

      await expect(payments.updateCvc('card_123', cvcElement)).resolves.toEqual({
        error: { error: 'session expired' },
      });
    });
  });
});
