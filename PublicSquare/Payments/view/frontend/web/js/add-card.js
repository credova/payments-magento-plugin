/**
 * Adds a card to the customer's saved cards on the My Account page. add-card.phtml starts it with
 * x-magento-init and passes the public key and the card input customization JSON.
 */
define(['jquery', 'publicsquarejs', 'Magento_Ui/js/modal/alert', 'mage/validation'], function ($, psqSdk, modal) {
  'use strict';

  return function (config) {
    let formVisible = false;
    const psqPubKey = config.publicKey;
    const cardInputCustomizationJSON = config.cardInputCustomization;
    const cardInputCustomization = JSON.parse(cardInputCustomizationJSON || '{}');
    let psq;
    let $modal;
    let cardReady;

    // cc elements bound by psq payments js
    let $card;

    async function save() {
      const $form = $('#psq-save-to-vault');
      const $saveButton = $('.psq-form__button--primary');
      // One save at a time: a second click while the card tokenizes can save a duplicate card.
      if ($saveButton.prop('disabled')) {
        return;
      }
      $saveButton.prop('disabled', true);
      let submitted = false;
      try {
        if (!$card || !$card.metadata.valid) {
          console.warn('Invalid card! %o', $card && $card.metadata);

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
        console.log('Creating new card...');
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
        submitted = true;
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
      } finally {
        // After a submit the page leaves. Otherwise, let the shopper fix the problem and save again.
        if (!submitted) {
          $saveButton.prop('disabled', false);
        }
      }
    }

    function closeForm() {
      // Remove class last
      $modal.addClass('psq-add-card__form--hidden');
    }

    async function mountCard() {
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

    function openForm() {
      // Add class first
      if (!$modal) {
        $modal = $('.psq-add-card__form');
      }
      $modal.removeClass('psq-add-card__form--hidden');

      // Mount once, also when the shopper clicks again while the SDK loads.
      if (!cardReady) {
        cardReady = mountCard().catch((err) => {
          cardReady = null;
          console.error('Failed to load the card form!', err);
        });
      }
      return cardReady;
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
