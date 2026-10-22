import $ from 'jquery';

/**
 * Stands in for a Magento UI component class (uiClass) so a renderer's own methods run in a test.
 *
 * It copies `defaults` and the constructor config onto the instance, gives each method a
 * `this._super()` that calls the base method of the same name, and then runs `initialize()`.
 *
 * @param {Record<string, Function>} baseMethods Methods of the parent component, such as `validate`.
 */
export function uiComponent(baseMethods = {}) {
  return {
    extend({ defaults = {}, ...methods }) {
      return function Component(config = {}) {
        const instance = { ...structuredClone(defaults), ...config };
        const names = new Set([...Object.keys(baseMethods), ...Object.keys(methods)]);
        for (const name of names) {
          instance[name] = name in methods ? withSuper(methods[name], baseMethods[name]) : baseMethods[name];
        }
        // Like uiClass, return the instance whatever initialize() returns.
        instance.initialize?.();
        return instance;
      };
    },
  };
}

/**
 * Gets the URL and body of the first call to a place-order fake.
 *
 * @param {import('vitest').Mock} placeOrder The fake for `Magento_Checkout/js/model/place-order`.
 */
export function placeOrderRequest(placeOrder) {
  const [[url, body]] = placeOrder.mock.calls;
  return { url, body };
}

/**
 * Fails like Magento's place-order model. Except on a 401, its error processor shows the parsed
 * server error, or a default message, in the message container it gets. Then the request rejects.
 *
 * @param {Record<string, unknown>|undefined} responseJSON Body of the failed response, if any.
 * @param {number} status HTTP status; 0 is a timeout or a network error.
 */
export function failedPlaceOrder(responseJSON, status = 400) {
  const responseText = responseJSON === undefined ? '' : JSON.stringify(responseJSON);
  const response = { status, responseJSON, responseText };
  return (serviceUrl, payload, messageContainer) => {
    if (status !== 401) {
      let error;
      try {
        error = JSON.parse(responseText);
      } catch {
        error = { message: 'Something went wrong with your request. Please try again later.' };
      }
      messageContainer.addErrorMessage(error);
    }
    return $.Deferred().reject(response).promise();
  };
}

function withSuper(method, parent) {
  return function (...args) {
    const previous = this._super;
    this._super = (...superArgs) => (parent ? parent.apply(this, superArgs) : this);
    try {
      return method.apply(this, args);
    } finally {
      this._super = previous;
    }
  };
}
