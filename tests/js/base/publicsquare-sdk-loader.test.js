import { afterEach, describe, expect, it, vi } from 'vitest';
import { loadAmdModule } from '../helpers/amd.js';

const LOADER = 'PublicSquare/Payments/view/base/web/js/publicsquare-sdk-loader.js';

/** A RequireJS stand-in that loads `sdk` when the loader asks for the SDK URL. */
function fakeRequireJs(sdk) {
  const requireJs = vi.fn((modules, onLoad) => onLoad(sdk));
  window.require = requireJs;
  return requireJs;
}

function setCurrentScript(script) {
  Object.defineProperty(document, 'currentScript', { configurable: true, get: () => script });
}

describe('publicsquare-sdk-loader', () => {
  afterEach(() => {
    vi.useRealTimers();
    delete window.require;
    delete window.define;
    delete window.publicsquarejs;
    delete window.PublicSquare;
    delete window.publicsquareSdkLoadTimeoutMs;
    delete document.currentScript;
  });

  it('loads the SDK with RequireJS and initializes it with the API key', async () => {
    const sdk = { init: vi.fn((apiKey) => ({ apiKey })) };
    const requireJs = fakeRequireJs(sdk);

    const loader = loadAmdModule(LOADER);

    await expect(loader.init('pk_test_key')).resolves.toEqual({ apiKey: 'pk_test_key' });
    // Match the host only, so an SDK version bump does not break the test.
    expect(requireJs.mock.calls[0][0]).toEqual([expect.stringMatching(/^https:\/\/js\.publicsquare\.com\//)]);
  });

  it('passes the options to the SDK and exposes the initialized cards API', async () => {
    const cards = { create: vi.fn() };
    const sdk = { init: vi.fn(() => ({ cards })) };
    fakeRequireJs(sdk);

    const loader = loadAmdModule(LOADER);
    await loader.init('pk_test_key', { env: 'test' });

    expect(sdk.init).toHaveBeenCalledWith('pk_test_key', { env: 'test' });
    // add-card.phtml reads cards from the loader after init.
    expect(loader.cards).toBe(cards);
  });

  it('resolves to the SDK itself when its init returns nothing', async () => {
    const sdk = { init: vi.fn(), cards: { create: vi.fn() } };
    fakeRequireJs(sdk);

    const loader = loadAmdModule(LOADER);

    await expect(loader.init('pk_test_key')).resolves.toBe(sdk);
    expect(loader.cards).toBe(sdk.cards);
  });

  it('has no cards API before init', () => {
    expect(loadAmdModule(LOADER).cards).toBeNull();
  });

  describe('finding the SDK export', () => {
    const sdk = { init: () => undefined };

    it.each([
      ['the AMD module', () => sdk],
      ['the default export of the AMD module', () => ({ default: sdk })],
      ['window.publicsquarejs', () => ((window.publicsquarejs = sdk), undefined)],
      ['window.PublicSquare', () => ((window.PublicSquare = { default: sdk }), undefined)],
    ])('uses %s', async (_where, exportSdk) => {
      window.require = (modules, onLoad) => onLoad(exportSdk());

      await expect(loadAmdModule(LOADER).init('pk_test_key')).resolves.toBe(sdk);
    });

    it('rejects when no export has an init function', async () => {
      window.publicsquarejs = { init: 'not a function' };
      fakeRequireJs({});

      await expect(loadAmdModule(LOADER).init('pk_test_key')).rejects.toThrow(
        'PublicSquare SDK loaded, but no compatible global/module export was found.',
      );
    });
  });

  describe('loading the script', () => {
    it('requests the script once for concurrent and later init calls', async () => {
      const sdk = { init: vi.fn() };
      const requireJs = fakeRequireJs(sdk);
      const loader = loadAmdModule(LOADER);

      await Promise.all([loader.init('pk_a'), loader.init('pk_b')]);
      await loader.init('pk_c');

      expect(requireJs).toHaveBeenCalledTimes(1);
      expect(sdk.init).toHaveBeenCalledTimes(3);
    });

    it('rejects when RequireJS is not available', async () => {
      await expect(loadAmdModule(LOADER).init('pk_test_key')).rejects.toThrow(
        'RequireJS is not available to load the PublicSquare SDK.',
      );
    });

    it('rejects when the script fails to load, then retries on the next init', async () => {
      const sdk = { init: vi.fn() };
      window.require = vi
        .fn()
        .mockImplementationOnce((modules, onLoad, onError) => onError(new Error('net::ERR_FAILED')))
        .mockImplementationOnce((modules, onLoad) => onLoad(sdk));
      const loader = loadAmdModule(LOADER);

      await expect(loader.init('pk_test_key')).rejects.toThrow('Unable to load PublicSquare SDK script.');
      await expect(loader.init('pk_test_key')).resolves.toBe(sdk);
      expect(window.require).toHaveBeenCalledTimes(2);
    });

    it('rejects when the script does not load in time', async () => {
      vi.useFakeTimers();
      window.publicsquareSdkLoadTimeoutMs = 50;
      window.require = vi.fn();

      const result = loadAmdModule(LOADER).init('pk_test_key');
      const assertion = expect(result).rejects.toThrow('PublicSquare SDK load timed out after 50ms.');
      await vi.advanceTimersByTimeAsync(50);

      await assertion;
    });

    it('ignores a timeout override that is not a positive number', async () => {
      vi.useFakeTimers();
      window.publicsquareSdkLoadTimeoutMs = -1;
      window.require = vi.fn();

      const result = loadAmdModule(LOADER).init('pk_test_key');
      const assertion = expect(result).rejects.toThrow('PublicSquare SDK load timed out after 15000ms.');
      await vi.advanceTimersByTimeAsync(15000);

      await assertion;
    });
  });

  // The SDK injects plain <script> tags with UMD bundles. If they see define.amd, they register an
  // anonymous AMD module, which RequireJS rejects, instead of setting a browser global.
  describe('hiding AMD from scripts the SDK injects', () => {
    function installDefine() {
      const define = vi.fn();
      define.amd = { jQuery: true };
      window.define = define;
      return define;
    }

    it('hides define.amd from plain scripts while the SDK initializes', async () => {
      installDefine();
      setCurrentScript(document.createElement('script'));
      let amdDuringInit = 'not read';
      fakeRequireJs({ init: () => (amdDuringInit = window.define.amd) });

      await loadAmdModule(LOADER).init('pk_test_key');

      expect(amdDuringInit).toBeUndefined();
    });

    it('keeps define.amd for scripts RequireJS loads', async () => {
      const define = installDefine();
      const requireScript = document.createElement('script');
      requireScript.setAttribute('data-requiremodule', 'some/module');
      setCurrentScript(requireScript);
      let amdDuringInit = 'not read';
      fakeRequireJs({
        init: () => {
          amdDuringInit = window.define.amd;
          window.define('some/module', [], () => ({}));
        },
      });

      await loadAmdModule(LOADER).init('pk_test_key');

      expect(amdDuringInit).toBe(define.amd);
      expect(define).toHaveBeenCalledWith('some/module', [], expect.any(Function));
    });

    it('restores the original define after init succeeds or fails', async () => {
      const define = installDefine();
      const sdk = { init: vi.fn().mockResolvedValueOnce(undefined).mockRejectedValueOnce(new Error('bad key')) };
      fakeRequireJs(sdk);
      const loader = loadAmdModule(LOADER);

      await loader.init('pk_test_key');
      expect(window.define).toBe(define);

      await expect(loader.init('pk_bad_key')).rejects.toThrow('bad key');
      expect(window.define).toBe(define);
    });
  });
});
