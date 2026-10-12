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
});
