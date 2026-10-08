define(['uiComponent', 'Magento_Checkout/js/model/payment/renderer-list'], function (Component, rendererList) {
  'use strict';
  rendererList.push({
    type: 'publicsquare_payments_cc_vault',
    component: 'PublicSquare_Payments/js/view/payment/method-renderer/vault',
    group: 'vaultGroup',
  });
  /**
   * Add view logic here if needed
   */
  return Component.extend({});
});
