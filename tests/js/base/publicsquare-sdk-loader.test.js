import { afterEach, describe, expect, it, vi } from 'vitest';
import { loadAmdModule } from '../helpers/amd.js';

const LOADER = 'PublicSquare/Payments/view/base/web/js/publicsquare-sdk-loader.js';

describe('publicsquare-sdk-loader', () => {
  afterEach(() => {
    delete window.require;
  });

  it('loads the SDK with RequireJS and initializes it with the API key', async () => {
    const sdk = { init: vi.fn((apiKey) => ({ apiKey })) };
    window.require = (modules, onLoad) => onLoad(sdk);

    const loader = loadAmdModule(LOADER);

    await expect(loader.init('pk_test_key')).resolves.toEqual({ apiKey: 'pk_test_key' });
  });
});
