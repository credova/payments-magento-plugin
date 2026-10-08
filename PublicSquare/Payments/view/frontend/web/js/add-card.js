/**
 * Adds a card to the customer's saved cards on the My Account page. add-card.phtml starts it with
 * x-magento-init and passes the public key and the card input customization JSON.
 */
define(['jquery', 'publicsquarejs', 'Magento_Ui/js/modal/alert'], function ($, psqSdk, modal) {
  'use strict';

  return function (config) {
    let formVisible = false;
    const psqPubKey = config.publicKey;
    const cardInputCustomizationJSON = config.cardInputCustomization;
    const cardInputCustomization = JSON.parse(cardInputCustomizationJSON || '{}');
    let psq;
    let $modal;

    // cc elements bound by psq payments js
    let $card;

    async function save() {
      const $form = $('#psq-save-to-vault');
      try {
        if (!$card || !$card.metadata.valid) {
          console.warn('Invalid card! %j', $card.metadata);

          modal({
            title: 'Error',
            content: 'The card is invalid. Please check the card details and try again.',
            clickableOverlay: true,
            actions: {
              always: function () {
                /*noop*/
              },
            },
          });
          return;
        }
        const cardholderName = $('#psq-form-cardholder-name').val();
        console.log('Creating new card for cardholder %s', cardholderName);
        const card = await psqSdk.cards.create({ cardholder_name: cardholderName, card: $card });
        console.log('Card created. Posting to vault...');

        // Update hidden form with information to save to vault
        $('#psq-new-card-id').val(card.id);
        $('#psq-new-card-exp-year').val(card.exp_year);
        $('#psq-new-card-exp-month').val(card.exp_month);
        $('#psq-new-card-details').val(
          JSON.stringify({
            type: card.brand,
            maskedCC: card.last4,
            expirationDate: `${card.exp_month}/${card.exp_year}`,
          }),
        );
        if (!$form.valid()) {
          console.warn('Found issues in form!');
          modal({
            title: 'Error',
            content: 'Form information incorrect.',
            clickableOverlay: true,
            actions: {
              always: function () {
                /*noop*/
              },
            },
          });
          return;
        }
        // Submit to the Customer/Card controller.
        $form.trigger('submit');
      } catch (err) {
        console.error('Failed to save card!', err);
        modal({
          title: 'Error',
          content: 'Unknown error creating card.',
          clickableOverlay: true,
          actions: {
            always: function () {
              /*noop*/
            },
          },
        });
      }
    }

    function closeForm() {
      // Remove class last
      $modal.addClass('psq-add-card__form--hidden');
    }

    async function openForm() {
      // Add class first
      if (!$modal) {
        $modal = $('.psq-add-card__form');
      }
      $modal.removeClass('psq-add-card__form--hidden');

      if (!$card) {
        psq = await psqSdk.init(psqPubKey);
        $card = psq.createCardElement(cardInputCustomization);
        $card.mount('#psq-card-element');
        $('.psq-form__button--cancel').click(() => {
          formVisible = false;
          closeForm();
        });
        $('.psq-form__button--primary').click(async () => {
          await save();
        });
      }
    }

    $('#psq-cc-add-button').click(async () => {
      console.log('PSQ: Add CC button clicked. formVisible was %s', formVisible);
      formVisible = !formVisible;
      if (formVisible) {
        await openForm();
      } else {
        await closeForm();
      }
    });
  };
});
