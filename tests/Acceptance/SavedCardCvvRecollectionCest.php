<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/** Saved-card CVV re-entry at checkout. See tests/AGENTS.md for the psq-rule group. */
class SavedCardCvvRecollectionCest extends AcceptanceBase
{
    const SETTING_PATH = 'payment/publicsquare_payments/require_cvv_new_shipping_address';
    const CUSTOMER_EMAIL = 'roni_cost@example.com';

    const CVV_FIELD = '.payment-method._active .psq-cvv-recollection';
    const CVV_IFRAME = '.payment-method._active .psq-cvv-recollection-field iframe';
    const CVV_ERROR = '.payment-method._active .psq-cvv-recollection .mage-error';
    const SUBMIT_BUTTON = '.payment-method._active button[type="submit"]';

    const CVV_REQUIRED_MESSAGE = "Please re-enter your card's security code to continue.";

    /** @var string|null The setting's value before the test, or null when it was not set */
    private $originalSetting;

    public function _before(AcceptanceTester $I)
    {
        parent::_before($I);
        $vaultEnabled = $I->grabFromDatabase(
            'core_config_data',
            'value',
            ['path' => 'payment/publicsquare_payments_cc_vault/active', 'scope' => 'default', 'scope_id' => 0]
        );
        $setting = $I->grabFromDatabase(
            'core_config_data',
            'value',
            ['path' => self::SETTING_PATH, 'scope' => 'default', 'scope_id' => 0]
        );
        $this->originalSetting = $setting === false || $setting === null ? null : (string) $setting;
        if (!$vaultEnabled) {
            $this->_saveConfig($I, 'payment/publicsquare_payments_cc_vault/active', '1');
        }
        $this->_saveConfig($I, self::SETTING_PATH, '1');
        $this->_runMagentoCommand($I, 'cache:clean config');

        $this->_initialize($I);
        $I->amOnPage('/customer/account/logout');
        $this->_customerLogin($I);
    }

    public function _after(AcceptanceTester $I)
    {
        if ($this->originalSetting === null) {
            $I->executeOnDatabase(sprintf(
                "DELETE FROM core_config_data WHERE path = '%s' AND scope = 'default' AND scope_id = 0",
                self::SETTING_PATH
            ));
        } else {
            $this->_saveConfig($I, self::SETTING_PATH, $this->originalSetting);
        }
        $this->_runMagentoCommand($I, 'cache:clean config');
        parent::_after($I);
    }

    public function asksForTheCvvOnceForANewAddress(AcceptanceTester $I)
    {
        $publicHash = $this->_ensureSavedCardWithAPayment($I);
        $address = $this->_uniqueAddress();

        $this->_addProductToCart($I);
        $this->_goToCheckoutWithNewShippingAddress($I, $address);
        $this->_selectSavedCard($I, $publicHash);
        $I->waitForElementVisible(self::CVV_FIELD, 30);
        $I->see('For your security, please re-enter the CVV for this card.');
        $this->_fillCvv($I, '123');
        $this->_placeOrder($I);
        $this->_waitForCheckoutResult($I, 'Thank you for your purchase!');

        // The card has now shipped there, so the next order to the same address goes through without it.
        $this->_addProductToCart($I);
        $this->_goToCheckoutWithNewShippingAddress($I, $address);
        $this->_selectSavedCard($I, $publicHash);
        $I->dontSeeElement(self::CVV_FIELD);
        $this->_placeOrder($I);
        $this->_waitForCheckoutResult($I, 'Thank you for your purchase!');
    }

    /** Only the prompt: with PublicSquare's rule on, the order itself would still be rejected (psq-rule group). */
    public function doesNotAskForTheCvvWhenTheSettingIsOff(AcceptanceTester $I)
    {
        $this->_saveConfig($I, self::SETTING_PATH, '0');
        $this->_runMagentoCommand($I, 'cache:clean config');
        $publicHash = $this->_ensureSavedCardWithAPayment($I);

        $this->_addProductToCart($I);
        $this->_goToCheckoutWithNewShippingAddress($I, $this->_uniqueAddress());
        $this->_selectSavedCard($I, $publicHash);
        $I->dontSeeElement(self::CVV_FIELD);
    }

    /** Its CVV was collected when the card was entered, so a card with no payments isn't asked again. */
    public function doesNotAskForACardWithNoPayments(AcceptanceTester $I)
    {
        $publicHash = $this->_addCardInMyAccount($I, '4242424242424242');

        $this->_addProductToCart($I);
        $this->_goToCheckoutWithNewShippingAddress($I, $this->_uniqueAddress());
        $this->_selectSavedCard($I, $publicHash);
        $I->dontSeeElement(self::CVV_FIELD);
    }

    public function doesNotPlaceTheOrderWithoutTheCvv(AcceptanceTester $I)
    {
        $publicHash = $this->_ensureSavedCardWithAPayment($I);
        $orders = $I->grabNumRecords('sales_order', ['customer_email' => self::CUSTOMER_EMAIL]);

        $this->_addProductToCart($I);
        $this->_goToCheckoutWithNewShippingAddress($I, $this->_uniqueAddress());
        $this->_selectSavedCard($I, $publicHash);
        $I->waitForElementVisible(self::CVV_FIELD, 30);
        $this->_placeOrder($I);

        $I->waitForElementVisible(self::CVV_ERROR, 30);
        $I->see("Enter the card's security code.", self::CVV_ERROR);
        $I->seeNumRecords($orders, 'sales_order', ['customer_email' => self::CUSTOMER_EMAIL]);
    }

    public function asksForTheCvvAgainAfterTheShopperSwitchesBack(AcceptanceTester $I)
    {
        $publicHash = $this->_ensureSavedCardWithAPayment($I);

        $this->_addProductToCart($I);
        $this->_goToCheckoutWithNewShippingAddress($I, $this->_uniqueAddress());
        $this->_selectSavedCard($I, $publicHash);
        $I->waitForElementVisible(self::CVV_FIELD, 30);

        $this->_makeSurePaymentMethodIsVisible($I);
        $this->_waitForAjax($I);
        $I->dontSeeElement(self::CVV_FIELD);

        $this->_selectSavedCard($I, $publicHash);
        $I->waitForElementVisible(self::CVV_FIELD, 30);
        $this->_fillCvv($I, '123');
        $this->_placeOrder($I);
        $this->_waitForCheckoutResult($I, 'Thank you for your purchase!');
    }

    public function letsTheShopperPayWithANewCardInstead(AcceptanceTester $I)
    {
        $publicHash = $this->_ensureSavedCardWithAPayment($I);

        $this->_addProductToCart($I);
        $this->_goToCheckoutWithNewShippingAddress($I, $this->_uniqueAddress());
        $this->_selectSavedCard($I, $publicHash);
        $I->waitForElementVisible(self::CVV_FIELD, 30);

        $this->_fillCardForm($I);
        $this->_placeOrder($I);
        $this->_waitForCheckoutResult($I, 'Thank you for your purchase!');
    }

    /** Other payment methods are unaffected. */
    public function leavesOtherPaymentMethodsAlone(AcceptanceTester $I)
    {
        $this->_addProductToCart($I);
        $this->_goToCheckoutWithNewShippingAddress($I, $this->_uniqueAddress());
        $I->waitForElementClickable('#checkmo', 30);
        $I->click('#checkmo');
        $this->_waitForLoading($I);
        $I->dontSeeElement('.psq-cvv-recollection');
        $this->_placeOrder($I);
        $this->_waitForCheckoutResult($I, 'Thank you for your purchase!');

        $statement = $I->getDbh()->prepare(
            'SELECT p.method FROM sales_order o JOIN sales_order_payment p ON p.parent_id = o.entity_id'
            . ' WHERE o.customer_email = ? ORDER BY o.entity_id DESC LIMIT 1'
        );
        $statement->execute([self::CUSTOMER_EMAIL]);
        $I->assertSame('checkmo', $statement->fetchColumn());
    }

    /**
     * A REST order that skips the CVV prompt is rejected with "re-enter your security code".
     *
     * @group psq-rule
     */
    public function rejectsASavedCardOrderThatSkipsTheCvvPrompt(AcceptanceTester $I)
    {
        $publicHash = $this->_ensureSavedCardWithAPayment($I);
        $orders = $I->grabNumRecords('sales_order', ['customer_email' => self::CUSTOMER_EMAIL]);

        $this->_addProductToCart($I);
        $this->_goToCheckoutWithNewShippingAddress($I, $this->_uniqueAddress());
        $response = $I->executeAsyncJS(
            'const done = arguments[arguments.length - 1];
            fetch("/rest/default/V1/carts/mine/payment-information", {
                method: "POST",
                credentials: "same-origin",
                headers: {"Content-Type": "application/json", "X-Requested-With": "XMLHttpRequest"},
                body: JSON.stringify({paymentMethod: {
                    method: "publicsquare_payments_cc_vault",
                    additional_data: {public_hash: arguments[0], idempotencyKey: String(Date.now())}
                }})
            }).then(function (r) {
                return r.json().then(function (body) { done({status: r.status, message: body.message}); });
            }).catch(function (e) { done({status: 0, message: String(e)}); });',
            [$publicHash]
        );

        $I->assertSame(400, $response['status']);
        $I->assertSame(self::CVV_REQUIRED_MESSAGE, $response['message']);
        $I->seeNumRecords($orders, 'sales_order', ['customer_email' => self::CUSTOMER_EMAIL]);
    }

    /**
     * With the setting off, PublicSquare rejects first; checkout then asks for the CVV and the retry goes through.
     *
     * @group psq-rule
     */
    public function asksForTheCvvAfterPublicSquareRejectsThePayment(AcceptanceTester $I)
    {
        $this->_saveConfig($I, self::SETTING_PATH, '0');
        $this->_runMagentoCommand($I, 'cache:clean config');
        $publicHash = $this->_ensureSavedCardWithAPayment($I);

        $this->_addProductToCart($I);
        $this->_goToCheckoutWithNewShippingAddress($I, $this->_uniqueAddress());
        $this->_selectSavedCard($I, $publicHash);
        $I->dontSeeElement(self::CVV_FIELD);
        $this->_placeOrder($I);

        $I->waitForText(self::CVV_REQUIRED_MESSAGE, 60);
        $I->waitForElementVisible(self::CVV_FIELD, 30);
        $this->_fillCvv($I, '123');
        $this->_placeOrder($I);
        $this->_waitForCheckoutResult($I, 'Thank you for your purchase!');
    }

    private function _saveConfig(AcceptanceTester $I, string $path, string $value): void
    {
        $I->executeOnDatabase(sprintf(
            "INSERT INTO core_config_data (scope, scope_id, path, value) VALUES ('default', 0, '%s', '%s')"
            . ' ON DUPLICATE KEY UPDATE value = VALUES(value)',
            $path,
            $value
        ));
    }

    private function _uniqueAddress(): array
    {
        return [
            'street' => sprintf('%s Acceptance Ave', uniqid()),
            'city' => 'Austin',
            'region' => 'Texas',
            'postcode' => '78701',
        ];
    }

    private function _customerId(AcceptanceTester $I): int
    {
        return (int) $I->grabFromDatabase('customer_entity', 'entity_id', ['email' => self::CUSTOMER_EMAIL]);
    }

    /** Public hash of a saved 4242 card with an earlier payment, paying with a new card first if there's none. */
    private function _ensureSavedCardWithAPayment(AcceptanceTester $I): string
    {
        $statement = $I->getDbh()->prepare(
            "SELECT v.public_hash FROM vault_payment_token v"
            . " JOIN sales_order o ON o.customer_id = v.customer_id"
            . " JOIN sales_order_payment p ON p.parent_id = o.entity_id"
            . " WHERE v.customer_id = ? AND v.is_active = 1 AND v.is_visible = 1"
            . " AND v.payment_method_code = 'publicsquare_payments' AND v.details LIKE '%\"maskedCC\":\"4242\"%'"
            . " AND p.last_trans_id LIKE 'pmt\\_%'"
            . " AND p.additional_information LIKE CONCAT('%\"cardId\":\"', v.gateway_token, '\"%')"
            . " ORDER BY v.entity_id DESC LIMIT 1"
        );
        $statement->execute([$this->_customerId($I)]);
        if ($hash = $statement->fetchColumn()) {
            return $hash;
        }

        $this->_addProductToCart($I);
        $this->_goToCheckout($I);
        $this->_checkoutWithCard($I, '4242424242424242', 'Thank you for your purchase!', false, true);
        $statement->execute([$this->_customerId($I)]);
        $hash = $statement->fetchColumn();
        $I->assertNotEmpty($hash, 'No saved card with a payment');
        return $hash;
    }

    /** Saves a card in My Account, which collects its CVV but doesn't charge it. Returns its public hash. */
    private function _addCardInMyAccount(AcceptanceTester $I, string $cardNumber): string
    {
        $newest = 'SELECT MAX(entity_id) FROM vault_payment_token WHERE customer_id = ?';
        $statement = $I->getDbh()->prepare($newest);
        $statement->execute([$this->_customerId($I)]);
        $before = (int) $statement->fetchColumn();

        $I->amOnPage('/vault/cards/listaction/');
        $I->waitForElementClickable('#psq-cc-add-button');
        $I->click('#psq-cc-add-button');
        $I->waitForElementVisible('#psq-card-element iframe', 30);
        $frame = $I->grabAttributeFrom('#psq-card-element iframe', 'id');
        $I->switchToIframe('//*[@id="' . $frame . '"]');
        $I->waitForElementVisible('//*[@id="cardNumber"]');
        $I->fillField('//*[@id="cardNumber"]', $cardNumber);
        $I->fillField('//*[@id="expirationDate"]', '12/29');
        $I->fillField('//*[@id="cvc"]', '123');
        $I->switchToIframe();
        $I->click('.psq-form__button--primary');

        // The page already shows its heading, so wait for the saved card itself.
        $statement = $I->getDbh()->prepare(
            'SELECT public_hash FROM vault_payment_token WHERE customer_id = ? AND entity_id > ? ORDER BY entity_id DESC'
        );
        for ($second = 0; $second < 60; $second++) {
            $statement->execute([$this->_customerId($I), $before]);
            if ($hash = $statement->fetchColumn()) {
                return $hash;
            }
            $I->wait(1);
        }
        $I->fail('The card was not saved');
    }

    /** Selects the saved card with this public hash; several saved cards can end in the same digits. */
    private function _selectSavedCard(AcceptanceTester $I, string $publicHash): void
    {
        $I->waitForJS(
            'return !!window.require && require.defined("uiRegistry")'
            . ' && require("uiRegistry").filter({publicHash: ' . json_encode($publicHash) . '}).length > 0;',
            30
        );
        $radioId = $I->executeJS(
            'return require("uiRegistry").filter({publicHash: arguments[0]})[0].index;',
            [$publicHash]
        );
        $I->waitForElementClickable('#' . $radioId, 30);
        $I->click('#' . $radioId);
        $this->_waitForLoading($I);
        // The CVV check is an AJAX call. Wait for it, so "no CVV field" means the check said so.
        $this->_waitForAjax($I);
    }

    private function _fillCvv(AcceptanceTester $I, string $cvv): void
    {
        $I->waitForElementVisible(self::CVV_IFRAME, 30);
        $frame = $I->grabAttributeFrom(self::CVV_IFRAME, 'id');
        $I->switchToIframe('//*[@id="' . $frame . '"]');
        $I->waitForElementVisible('#cvc');
        $I->fillField('#cvc', $cvv);
        $I->switchToIframe();
    }

    private function _placeOrder(AcceptanceTester $I): void
    {
        $I->waitForElementClickable(self::SUBMIT_BUTTON);
        $I->click(self::SUBMIT_BUTTON);
    }

    private function _waitForAjax(AcceptanceTester $I): void
    {
        $I->waitForJS('return !window.jQuery || window.jQuery.active === 0;', 30);
    }
}
