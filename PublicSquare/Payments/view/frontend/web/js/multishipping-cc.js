/**
 * The card form on the multishipping billing step. multishipping-cc.phtml starts it with x-magento-init, because the
 * checkout CSP blocks inline scripts.
 */
define(['jquery', 'PublicSquare_Payments/js/publicsquare_payments'], function ($) {
  'use strict';

  return function (config) {
    var code = config.code;
    var selector = '#publicsquare-elements-form';
    var apiKey = config.publicKey;
    const cardInputCustomization = JSON.parse(config.cardInputCustomization || '{}');

    function generateIdempotencyKey() {
      var timestamp = Date.now().toString();
      var random = Math.random().toString(36).substr(2, 9);
      return timestamp + random;
    }

    function unmount() {
      $('#' + code + '_cardholder_name').prop('disabled', true);
      $('#' + code + '_cardholder_name').val('');

      $('#' + code + '_card_id').prop('disabled', true);

      $('#' + code + '_idempotency_key').prop('disabled', true);
      $('#' + code + '_save_card').prop('disabled', true);
    }

    function mount() {
      if (window.publicsquare && typeof window.publicsquare.initElements === 'function') {
        window.publicsquare.initElements({ apiKey: apiKey, selector: selector, cardInputCustomization });
      }

      $('#' + code + '_cardholder_name').prop('disabled', false);
      $('#' + code + '_card_id').prop('disabled', false);
      $('#' + code + '_idempotency_key').prop('disabled', false);
      $('#' + code + '_save_card').prop('disabled', false);
    }

    $(function () {
      // ensure idempotency key exists on load
      var $idem = $('#' + code + '_idempotency_key');
      if (!$idem.val()) {
        $idem.val(generateIdempotencyKey());
      }

      mount();

      // Remount when user re-selects this method
      $(document).on('change', 'input[name="payment[method]"]', function () {
        if (this.value === 'publicsquare_payments' && this.checked) {
          mount();
        } else {
          unmount();
        }
      });

      // Adding this as there are some edge cases where going back doesn't successfully mount keeping it disabled
      $(document).on('click', '#' + code + '_cardholder_name', function (e) {
        var $el = $(this);
        if ($el.prop('disabled')) {
          // Remove disabled so it becomes enabled
          $el.prop('disabled', false);

          // Optionally: focus the element
          $el.focus();

          // Optionally: prevent default behaviour if any
          e.preventDefault();
        }
      });

      // Save card vault
      $(document).on('change', '#' + code + '_enable_vault', function () {
        $('#' + code + '_save_card').val(this.checked ? 'true' : '');
      });

      // Intercept submit to tokenize and set hidden fields
      var $form = $('#multishipping-billing-form');
      $form.on('submit.publicsquare', async function (e) {
        // If method radios exist, only intercept when our method is selected
        var method = $('input[name="payment[method]"]:checked').val();
        if (method && method !== 'publicsquare_payments') {
          return;
        }

        e.preventDefault();
        try {
          var card = window.publicsquare && window.publicsquare.cardElement;
          if (!card) {
            mount();
            card = window.publicsquare && window.publicsquare.cardElement;
          }
          // Read cardholder name from input
          var cardholderName = ($('#' + code + '_cardholder_name').val() || '').trim();
          // regenerate idempotency key on each submit attempt
          $('#' + code + '_idempotency_key').val(generateIdempotencyKey());
          var res = await window.publicsquare.createCard(cardholderName, card);
          if (res.id) {
            $('#' + code + '_card_id').val(res.id);
          }
          $form.off('submit.publicsquare').trigger('submit');
        } catch (err) {
          var msg = (err && err.message) || 'Error saving card.';
          $('#publicsquare_error_message').text(msg).show();
        }
      });
    });
  };
});
