import $ from 'jquery';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { loadAmdModule } from '../helpers/amd.js';
import { settle } from '../helpers/settle.js';

const MULTISHIPPING_CC = 'PublicSquare/Payments/view/frontend/web/js/multishipping-cc.js';

describe('multishipping-cc', () => {
  afterEach(() => {
    $(document).off();
    delete window.publicsquare;
    document.body.innerHTML = '';
  });

  it('mounts the card form with the config from x-magento-init', async () => {
    document.body.innerHTML = `
      <form id="multishipping-billing-form">
        <input id="publicsquare_payments_cardholder_name" disabled>
        <div id="publicsquare-elements-form"></div>
        <input id="publicsquare_payments_card_id" disabled>
        <input id="publicsquare_payments_idempotency_key">
      </form>`;
    window.publicsquare = { initElements: vi.fn() };

    loadAmdModule(MULTISHIPPING_CC, { jquery: $, 'PublicSquare_Payments/js/publicsquare_payments': {} })({
      code: 'publicsquare_payments',
      publicKey: 'pk_test_key',
      cardInputCustomization: '{"theme":"dark"}',
    });
    await settle();

    expect(window.publicsquare.initElements).toHaveBeenCalledExactlyOnceWith({
      apiKey: 'pk_test_key',
      selector: '#publicsquare-elements-form',
      cardInputCustomization: { theme: 'dark' },
    });
    expect($('#publicsquare_payments_cardholder_name').prop('disabled')).toBe(false);
    expect($('#publicsquare_payments_idempotency_key').val()).not.toBe('');
  });
});
