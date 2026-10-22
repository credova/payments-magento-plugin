define(['jquery', 'publicsquare_payments'], function ($, publicsquare) {
  const firstNameSelector = '#order-billing_address_firstname';
  const lastNameSelector = '#order-billing_address_lastname';
  const elementsFormSelector = '#publicsquare-elements-form';
  const paymentMethodNonceSelector = '#publicsquare_payments_payment_method_nonce';
  let config = {};
  let element = $(elementsFormSelector);
  let originalOrderSubmit;

  async function onSubmit() {
    const $form = $('#edit_form');
    try {
      $form.trigger('processStart');
      const cardholder_name = [$(firstNameSelector).val(), $(lastNameSelector).val()].filter(Boolean).join(' ');
      if (!cardholder_name) {
        alert('Cardholder name is required');
        throw new Error('Cardholder name is required');
      } else if (!publicsquare.cardElement || !publicsquare.cardElement.metadata.valid) {
        alert('The card is invalid. Please check the card details and try again.');
        throw new Error('The card is invalid. Please check the card details and try again.');
      }
      const card = await publicsquare.createCard(cardholder_name, publicsquare.cardElement);
      $(paymentMethodNonceSelector).val(card.id);
      if (!$form.valid()) {
        $form.trigger('processStop');
        return;
      }
      originalOrderSubmit();
    } catch {
      $form.trigger('processStop');
    }
  }

  function enableSubmitHandler() {
    if (!window.order || !window.order.submit) return;
    if (!originalOrderSubmit) {
      originalOrderSubmit = window.order.submit;
    }
    if (window.order.paymentMethod === 'publicsquare_payments') {
      window.order.submit = onSubmit;
    } else if (originalOrderSubmit) {
      window.order.submit = originalOrderSubmit;
    }
  }

  function watch() {
    const paymentMethods = document.querySelector('#edit_form');
    if (paymentMethods) {
      observer.observe(paymentMethods, {
        childList: true,
        subtree: true,
        attributes: true,
      });
    }
  }

  function observe() {
    if (document.querySelector('#edit_form')) {
      renderElements();
      watch();
    }
  }

  function renderElements() {
    element = $(elementsFormSelector);
    if (element.length && !element.children().length && !publicsquare.loading) {
      observer.disconnect();
      requestAnimationFrame(() => {
        publicsquare
          .initElements(
            {
              apiKey: config.pk || '',
              selector: elementsFormSelector,
            },
            () => {
              observe();
            },
          )
          // Magento can reload the payment block during the SDK load, so the mount target is gone. Watch again,
          // so the next reload mounts the card form. A retry now could loop when the SDK cannot load.
          .catch(watch);
      });
    }
    enableSubmitHandler();
  }

  const observer = new MutationObserver((mutations) => {
    if (mutations.find((cur) => $(cur.target).is($('.payment-method') || $(cur.target).has('.payment-method')))) {
      renderElements();
    }
  });

  function init(_config) {
    config = _config;
    observe();
  }

  return {
    init,
  };
});
