import { vi } from 'vitest';

/**
 * A Knockout-style observable: call with no argument to read, with one to write.
 */
export function observable(initial) {
  let value = initial;
  return function (next) {
    if (arguments.length) {
      value = next;
    }
    return value;
  };
}

/**
 * A settled jQuery-style promise, as mage/storage and place-order return.
 */
export function deferred(value, ok = true) {
  const promise = {
    done(callback) {
      if (ok) callback(value);
      return promise;
    },
    fail(callback) {
      if (!ok) callback(value);
      return promise;
    },
    always(callback) {
      callback(value);
      return promise;
    },
  };
  return promise;
}

/**
 * Builds a uiComponent from the spec passed to Component.extend, with `_super` stubbed.
 */
export function instantiate(spec, overrides = {}) {
  const instance = Object.create(spec);
  Object.assign(instance, spec.defaults, { _super: vi.fn(() => true) }, overrides);
  return instance;
}

/**
 * An observable that also supports subscribe, like quote.paymentMethod and quote.totals.
 */
export function subscribable(initial) {
  const read = observable(initial);
  const subscribers = [];
  const obs = function (next) {
    if (arguments.length) {
      read(next);
      subscribers.forEach((callback) => callback(next));
    }
    return read();
  };
  obs.subscribe = (callback) => subscribers.push(callback);
  return obs;
}
