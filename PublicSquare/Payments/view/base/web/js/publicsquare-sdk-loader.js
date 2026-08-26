define([], function () {
  'use strict';

  var SDK_URL = 'https://js.publicsquare.com/v1.16.0/';
  var DEFAULT_LOAD_TIMEOUT_MS = 15000;

  var sdkLoadPromise = null;
  var sdkReference = null;

  function getLoadTimeoutMs() {
    var override = window.publicsquareSdkLoadTimeoutMs;
    if (typeof override === 'number' && override > 0) {
      return override;
    }
    return DEFAULT_LOAD_TIMEOUT_MS;
  }

  function normalizeSdkCandidate(candidate) {
    if (candidate && typeof candidate.init === 'function') {
      return candidate;
    }

    if (candidate && candidate.default && typeof candidate.default.init === 'function') {
      return candidate.default;
    }

    return null;
  }

  function resolveSdk(loadedModule) {
    var candidates = [
      loadedModule,
      window.publicsquarejs,
      window.PublicSquare
    ];

    for (var i = 0; i < candidates.length; i += 1) {
      var sdk = normalizeSdkCandidate(candidates[i]);
      if (sdk) {
        return sdk;
      }
    }

    return null;
  }

  function getRequire() {
    if (window.require) {
      return window.require;
    }

    if (typeof require === 'function') {
      return require;
    }

    return null;
  }

  function loadSdkModule() {
    return new Promise(function (resolve, reject) {
      var loaderRequire = getRequire();
      if (!loaderRequire) {
        reject(new Error('RequireJS is not available to load the PublicSquare SDK.'));
        return;
      }

      var timeoutMs = getLoadTimeoutMs();
      var timeoutId = null;
      var settled = false;

      function cleanup() {
        if (timeoutId !== null) {
          clearTimeout(timeoutId);
          timeoutId = null;
        }
      }

      function settle(fn) {
        if (settled) {
          return;
        }
        settled = true;
        cleanup();
        fn();
      }

      function onLoaded(loadedModule) {
        settle(function () {
          var resolvedSdk = resolveSdk(loadedModule);
          if (resolvedSdk) {
            resolve(resolvedSdk);
            return;
          }
          reject(new Error('PublicSquare SDK loaded, but no compatible global/module export was found.'));
        });
      }

      function onError() {
        settle(function () {
          reject(new Error('Unable to load PublicSquare SDK script.'));
        });
      }

      timeoutId = setTimeout(function () {
        settle(function () {
          reject(new Error('PublicSquare SDK load timed out after ' + timeoutMs + 'ms.'));
        });
      }, timeoutMs);

      loaderRequire([SDK_URL], onLoaded, onError);
    });
  }

  var amdGuardDepth = 0;
  var amdGuardOriginalDefine = null;
  var amdGuardOriginalAmd = null;
  var amdGuardWrappedDefine = null;

  function isRequireJsScript(script) {
    if (!script || typeof script.getAttribute !== 'function') {
      return false;
    }

    return script.getAttribute('data-requiremodule') !== null ||
      script.getAttribute('data-requirecontext') !== null;
  }

  function installAmdGuard() {
    if (amdGuardDepth === 0) {
      var current = window.define;
      if (typeof current === 'function') {
        amdGuardOriginalDefine = current;
        amdGuardOriginalAmd = current.amd;

        var wrapped = function () {
          return amdGuardOriginalDefine.apply(this, arguments);
        };

        // RequireJS-owned scripts (loaded via require([...])) carry
        // data-requiremodule/data-requirecontext attributes. They keep seeing
        // the original define.amd so AMD registration still works. Plain
        // <script> tags injected by the SDK do not carry these attributes and
        // see define.amd as undefined, which routes them through the UMD
        // browser-global branch where they assign window.X = factory().
        Object.defineProperty(wrapped, 'amd', {
          configurable: true,
          enumerable: true,
          get: function () {
            if (isRequireJsScript(document.currentScript)) {
              return amdGuardOriginalAmd;
            }
            return undefined;
          }
        });

        amdGuardWrappedDefine = wrapped;
        window.define = wrapped;
      }
    }

    amdGuardDepth += 1;
  }

  function uninstallAmdGuard() {
    if (amdGuardDepth <= 0) {
      return;
    }

    amdGuardDepth -= 1;
    if (amdGuardDepth !== 0) {
      return;
    }

    if (amdGuardWrappedDefine !== null) {
      if (window.define === amdGuardWrappedDefine) {
        window.define = amdGuardOriginalDefine;
      }

      amdGuardOriginalDefine = null;
      amdGuardOriginalAmd = null;
      amdGuardWrappedDefine = null;
    }
  }

  function runWithSdkAmdGuard(executor) {
    installAmdGuard();

    var released = false;
    function release() {
      if (released) {
        return;
      }
      released = true;
      uninstallAmdGuard();
    }

    try {
      var result = executor();

      if (result && typeof result.then === 'function') {
        return result.then(
          function (value) { release(); return value; },
          function (error) { release(); throw error; }
        );
      }

      release();
      return result;
    } catch (error) {
      release();
      throw error;
    }
  }

  function ensureSdkLoaded() {
    if (sdkReference) {
      return Promise.resolve(sdkReference);
    }

    if (sdkLoadPromise) {
      return sdkLoadPromise;
    }

    sdkLoadPromise = loadSdkModule().then(
      function (sdk) {
        sdkReference = sdk;
        return sdk;
      },
      function (error) {
        sdkLoadPromise = null;
        throw error;
      }
    );

    return sdkLoadPromise;
  }

  var loader = {
    init: function (apiKey, options) {
      return ensureSdkLoaded().then(function (sdk) {
        return runWithSdkAmdGuard(function () {
          return Promise.resolve(sdk.init(apiKey, options)).then(function (initializedSdk) {
            sdkReference = initializedSdk || sdk;
            return sdkReference;
          });
        });
      });
    }
  };

  Object.defineProperty(loader, 'cards', {
    enumerable: true,
    configurable: true,
    get: function () {
      return sdkReference && sdkReference.cards;
    }
  });

  return loader;
});
