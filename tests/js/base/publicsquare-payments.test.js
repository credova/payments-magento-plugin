import { afterEach, describe, expect, it, vi } from 'vitest';
import { loadAmdModule } from '../helpers/amd.js';

const MODULE = 'PublicSquare/Payments/view/base/web/js/publicsquare_payments.js';

function fakeSdk() {
  const cvcElement = { mount: vi.fn() };
  return {
    cvcElement,
    createCardElement: vi.fn(() => ({ mount: vi.fn(), unmount: vi.fn() })),
    createElement: vi.fn(() => cvcElement),
    cards: { create: vi.fn(), updateCvc: vi.fn() },
  };
}

function load(sdk = fakeSdk()) {
  const loader = { init: vi.fn(() => Promise.resolve(sdk)) };
  return { publicsquare: loadAmdModule(MODULE, { publicsquarejs: loader }), loader, sdk };
}

describe('publicsquare_payments', () => {
  afterEach(() => {
    delete window.publicsquare;
  });

  it('initializes the SDK once, even when asked twice at the same time', async () => {
    const { publicsquare, loader, sdk } = load();

    const [first, second] = await Promise.all([
      publicsquare.ensureInitialized('pk_test_key'),
      publicsquare.ensureInitialized('pk_test_key'),
    ]);

    expect(loader.init).toHaveBeenCalledTimes(1);
    expect(loader.init).toHaveBeenCalledWith('pk_test_key');
    expect(first).toBe(sdk);
    expect(second).toBe(sdk);
  });

  it('retries initialization after a failure', async () => {
    const { publicsquare, loader, sdk } = load();
    loader.init.mockRejectedValueOnce(new Error('network'));

    await expect(publicsquare.ensureInitialized('pk_test_key')).rejects.toThrow('network');
    await expect(publicsquare.ensureInitialized('pk_test_key')).resolves.toBe(sdk);
  });

  it('mounts the card element on the shared SDK', async () => {
    const { publicsquare, loader, sdk } = load();
    await publicsquare.ensureInitialized('pk_test_key');

    await publicsquare.initElements({ apiKey: 'pk_test_key', selector: '#card' });

    expect(loader.init).toHaveBeenCalledTimes(1);
    expect(sdk.createCardElement.mock.results[0].value.mount).toHaveBeenCalledWith('#card');
  });

  it.each([
    ['visa', 'visa'],
    ['amex', 'american-express'],
    ['american_express', 'american-express'],
    ['Diners Club', 'diners-club'],
  ])('mounts a CVV element sized for a %s card', async (brand, elementBrand) => {
    const { publicsquare, sdk } = load();
    await publicsquare.ensureInitialized('pk_test_key');

    const element = publicsquare.createCvcElement('#psq-cvc-1', brand);

    expect(sdk.createElement).toHaveBeenCalledWith('cardVerificationCode', { cardBrand: elementBrand });
    expect(element.mount).toHaveBeenCalledWith('#psq-cvc-1');
  });

  it('leaves the CVV length open for an unknown brand', async () => {
    const { publicsquare, sdk } = load();
    await publicsquare.ensureInitialized('pk_test_key');

    publicsquare.createCvcElement('#psq-cvc-1', 'something-new');

    expect(sdk.createElement).toHaveBeenCalledWith('cardVerificationCode', {});
  });

  it('attaches the CVV to the saved card', async () => {
    const { publicsquare, sdk } = load();
    sdk.cards.updateCvc.mockResolvedValue({ id: 'card_123', modified_at: '2026-10-09T15:00:00Z' });
    await publicsquare.ensureInitialized('pk_test_key');

    const result = await publicsquare.updateCvc('card_123', sdk.cvcElement);

    expect(sdk.cards.updateCvc).toHaveBeenCalledWith('card_123', sdk.cvcElement);
    expect(result.id).toBe('card_123');
  });

  it('returns an error instead of throwing when the CVV update fails', async () => {
    const { publicsquare, sdk } = load();
    sdk.cards.updateCvc.mockRejectedValue(new Error('session expired'));
    await publicsquare.ensureInitialized('pk_test_key');

    await expect(publicsquare.updateCvc('card_123', sdk.cvcElement)).resolves.toEqual({
      error: { error: 'session expired' },
    });
  });

  it('lets a card be created again after a failed attempt', async () => {
    const { publicsquare, sdk } = load();
    sdk.cards.create.mockRejectedValueOnce(new Error('declined')).mockResolvedValueOnce({ id: 'card_123' });
    await publicsquare.ensureInitialized('pk_test_key');

    await expect(publicsquare.createCard('Billy Bob', {})).rejects.toThrow('declined');
    await expect(publicsquare.createCard('Billy Bob', {})).resolves.toEqual({ id: 'card_123' });
  });
});
