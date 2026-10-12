<?php

namespace Tests\Acceptance;

use Tests\Pages\AdminSalesOrderCreate;
use Tests\Support\AcceptanceTester;

/**
 * Flaky on the dev stack: the product grid or the card iframe is not ready in time. mise run test:acceptance skips this
 * group; run it on purpose with: vendor/bin/codecept run tests/Acceptance/ -g admin-order
 *
 * @group admin-order
 */
class AdminSalesOrderCreateCest extends AdminSalesOrderCreate
{
    public function successfulPayment(AcceptanceTester $I)
    {
        $this->_goToNewSalesOrder($I);
        $this->_addProductToOrder($I);
        $this->_addCustomerToOrder($I);
        $this->_addShippingMethodToOrder($I);
        $this->_addPaymentMethodToOrder($I);
        $this->_submitOrder($I);
    }

    public function declinedPayment(AcceptanceTester $I)
    {
        $this->_goToNewSalesOrder($I);
        $this->_addProductToOrder($I);
        $this->_addCustomerToOrder($I);
        $this->_addShippingMethodToOrder($I);
        // First, fail the payment
        $this->_addPaymentMethodToOrder($I, '4000000000009995');
        $this->_submitOrder($I, 'The payment could not be processed. Reason: Insufficient Funds');
        // Then, succeed the payment
        $this->_fillCardForm($I, '4242424242424242', '12/29', '123', '#publicsquare-elements-form');
        $this->_submitOrder($I);
    }

    public function rejectedPayment(AcceptanceTester $I)
    {
        $this->_goToNewSalesOrder($I);
        $this->_addProductToOrder($I);
        $this->_addCustomerToOrder($I);
        $this->_addShippingMethodToOrder($I);
        $this->_addPaymentMethodToOrder($I, '4100000000000019');
        $this->_submitOrder($I, 'The payment could not be completed. Please verify your details and try again.');
    }

    public function invalidPayment(AcceptanceTester $I)
    {
        $this->_goToNewSalesOrder($I);
        $this->_addProductToOrder($I);
        $this->_addCustomerToOrder($I);
        $this->_addShippingMethodToOrder($I);
        $this->_addPaymentMethodToOrder($I, '');
        $this->_submitOrder($I, 'The card is invalid. Please check the card details and try again.', true);
    }
}
