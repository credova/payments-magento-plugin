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
        return instance.initialize ? instance.initialize() : instance;
      };
    },
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
