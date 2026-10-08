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
 * Fails like Magento's place-order model. Its error processor shows the parsed server error in the
 * message container it gets, then the request rejects.
 *
 * @param {Record<string, unknown>} responseJSON Body of the failed response.
 */
export function failedPlaceOrder(responseJSON) {
  const response = { status: 400, responseJSON, responseText: JSON.stringify(responseJSON) };
  return (serviceUrl, payload, messageContainer) => {
    messageContainer.addErrorMessage(JSON.parse(response.responseText));
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
