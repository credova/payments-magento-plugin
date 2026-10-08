import { afterEach, describe, expect, it, vi } from 'vitest';
import { loadAmdModule } from '../helpers/amd.js';

const LOADER = 'PublicSquare/Payments/view/base/web/js/publicsquare-sdk-loader.js';

describe('publicsquare-sdk-loader', () => {
  afterEach(() => {
    delete window.require;
  });

  it('loads the SDK with RequireJS and initializes it with the API key', async () => {
    const sdk = { init: vi.fn((apiKey) => ({ apiKey })) };
    const requireJs = vi.fn((modules, onLoad) => onLoad(sdk));
    window.require = requireJs;

    const loader = loadAmdModule(LOADER);

    await expect(loader.init('pk_test_key')).resolves.toEqual({ apiKey: 'pk_test_key' });
    // Match the host only, so an SDK version bump does not break the test.
    expect(requireJs.mock.calls[0][0]).toEqual([expect.stringMatching(/^https:\/\/js\.publicsquare\.com\//)]);
  });
});
