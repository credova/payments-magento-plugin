/**
 * Reads a failed checkout request for the payment renderers: the message to show the shopper, and
 * whether a retry needs a new idempotency key.
 */
define([], function () {
  'use strict';

  const DEFAULT_MESSAGE = 'Something went wrong. Please try again or contact support for assistance.';

  // Fills %1 or %name placeholders from a Magento error's parameters, as Magento's messages model does.
  function fillParameters(message, parameters) {
    if (!parameters) {
      return message;
    }
    return message.replace(/%(\w+)/g, (placeholder, name) => {
      const key = /^\d+$/.test(name) ? Number(name) - 1 : name;
      return Object.prototype.hasOwnProperty.call(parameters, key) ? String(parameters[key]) : placeholder;
    });
  }

  return {
    /**
     * Gets the message to show for a failed tokenization or order request.
     *
     * @param {*} error The rejection value, such as a jqXHR or an Error.
     * @param {String} fallback The message to show when the error has none.
     * @returns {String}
     */
    message: function (error, fallback = DEFAULT_MESSAGE) {
      const response = error && error.responseJSON;
      const message = response && response.message;
      // Only a string can be shown. A number or an object would make fillParameters() throw.
      if (!message || typeof message !== 'string') {
        return fallback;
      }
      let text = message;
      try {
        // The server sometimes JSON-encodes the message.
        const decoded = JSON.parse(message);
        if (decoded && typeof decoded.message === 'string') {
          text = decoded.message;
        } else if (typeof decoded === 'string') {
          text = decoded;
        }
      } catch {
        // A plain-text message.
      }
      return fillParameters(text, response.parameters);
    },

    /**
     * Tells whether a failure is final, so that a retry needs a new idempotency key.
     *
     * A timeout, a network error, or a 5xx can arrive after the payment went through. The retry then
     * keeps the key, so PublicSquare returns the first result and does not charge the card again.
     *
     * @param {*} error The rejection value, such as a jqXHR or an Error.
     * @returns {Boolean}
     */
    isFinal: function (error) {
      const status = error && error.status;
      // An error that is not an HTTP response, such as a failed tokenize, sent no order request.
      if (typeof status !== 'number') {
        return true;
      }
      return status >= 400 && status < 500;
    },
  };
});
