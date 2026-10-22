<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Multishipping ("Check Out with Multiple Addresses") splits the cart into one order for each address. Each order
 * makes its own PSQ payment with the same card.
 */
class MultishippingCheckoutCest extends AcceptanceBase
{
    public function splitCartMakesOnePaymentForEachOrder(AcceptanceTester $I)
    {
        $this->_initialize($I);
        // Start from an empty cart. A failed earlier run can leave units in the customer's active quote.
        $I->executeOnDatabase(
            "UPDATE quote q JOIN customer_entity c ON c.entity_id = q.customer_id SET q.is_active = 0 "
            . "WHERE c.email = 'roni_cost@example.com' AND q.is_active = 1"
        );
        $this->_customerLogin($I);
        $this->_addProductToCart($I);
        $this->_changeCartQuantity($I, 2);

        $I->amOnPage('/multishipping/checkout/addresses/');
        $I->waitForText('Ship to Multiple Addresses');
        $this->_ensureSecondShippingAddress($I);
        // Multishipping shows one row for each unit. Send the last row to the second address.
        $I->executeJS(
            "const s = [...document.querySelectorAll('select[name^=\"ship[\"]')].pop(); s.selectedIndex = 1;"
        );
        $I->click('Go to Shipping Information');

        $I->waitForText('Select Shipping Method');
        $I->seeNumberOfElements('input[value="flatrate_flatrate"]', 2);
        foreach ($I->grabMultiple('input[value="flatrate_flatrate"]', 'id') as $id) {
            $I->checkOption("#$id");
        }
        $I->click('Continue to Billing Information');

        $I->waitForElementClickable('#p_method_publicsquare_payments', 30);
        $I->click('#p_method_publicsquare_payments');
        $I->fillField('#publicsquare_payments_cardholder_name', 'Veronica Costello');
        $this->_fillCardForm($I, '4242424242424242', '12/29', '123', '#payment_form_publicsquare_payments');
        $I->click('#payment-continue');

        $I->waitForText('Review Order', 30);
        $I->click('Place Order');
        $I->waitForText('Thank you for your purchase!', 60);

        $orders = array_map('trim', $I->grabMultiple('.orders-succeed .order-id a'));
        $I->assertCount(2, $orders);
        $statement = $I->getDbh()->prepare(
            'SELECT p.last_trans_id FROM sales_order o JOIN sales_order_payment p ON p.parent_id = o.entity_id '
            . 'WHERE o.increment_id IN (?, ?)'
        );
        $statement->execute($orders);
        $payments = $statement->fetchAll(\PDO::FETCH_COLUMN);
        $I->assertCount(2, array_unique($payments));
        foreach ($payments as $payment) {
            $I->assertStringStartsWith('pmt_', (string) $payment);
        }
    }

    private function _ensureSecondShippingAddress(AcceptanceTester $I): void
    {
        $count = (int) $I->executeJS("return document.querySelector('select[name^=\"ship[\"]').options.length;");
        if ($count > 1) {
            return;
        }
        $I->click('Enter a New Address');
        $I->waitForElementVisible('input[name="street[0]"]');
        $I->fillField('telephone', '1234567890');
        $I->fillField('street[0]', '500 Second Ave');
        $I->fillField('city', 'Wilmington');
        $I->selectOption('country_id', 'US');
        $I->selectOption('region_id', '15');
        $I->fillField('postcode', '19801');
        $I->click('Save Address');
        $I->waitForText('Ship to Multiple Addresses');
    }
}
