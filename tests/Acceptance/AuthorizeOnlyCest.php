<?php

namespace Tests\Acceptance;

use Tests\Pages\AdminOrderDetailPage;
use Tests\Support\AcceptanceTester;

/**
 * Payment Action = "Authorize Only": checkout authorizes the payment, and the admin captures it with an invoice or
 * voids it by canceling the order.
 */
class AuthorizeOnlyCest extends AdminOrderDetailPage
{
    const PAYMENT_ACTION_PATH = 'payment/publicsquare_payments/payment_action';

    /** @var string|null The payment action before the test, or null when it was not set */
    private $originalPaymentAction;

    public function _before(AcceptanceTester $I)
    {
        parent::_before($I);
        $action = $I->grabFromDatabase(
            'core_config_data',
            'value',
            ['path' => self::PAYMENT_ACTION_PATH, 'scope' => 'default', 'scope_id' => 0]
        );
        $this->originalPaymentAction = $action === false || $action === null ? null : (string) $action;
        $this->_savePaymentAction($I, 'authorize');
    }

    public function _after(AcceptanceTester $I)
    {
        if ($this->originalPaymentAction === null) {
            $I->executeOnDatabase(sprintf(
                "DELETE FROM core_config_data WHERE path = '%s' AND scope = 'default' AND scope_id = 0",
                self::PAYMENT_ACTION_PATH
            ));
            $this->_runMagentoCommand($I, 'cache:clean config');
        } else {
            $this->_savePaymentAction($I, $this->originalPaymentAction);
        }
        parent::_after($I);
    }

    public function invoicingCapturesTheAuthorizedPayment(AcceptanceTester $I)
    {
        $orderId = $this->_placeAuthorizedOrder($I);

        $this->_goToTheOrder($I);
        $I->click('#order_invoice');
        $I->waitForText('New Invoice');
        $I->seeOptionIsSelected('select[name="invoice[capture_case]"]', 'Capture Online');
        $I->waitForElementClickable('.order-totals-actions button.submit-button');
        $I->click('.order-totals-actions button.submit-button');
        $I->waitForText('The invoice has been created.', 60);

        $payment = $this->_payment($I, $orderId);
        $I->assertSame($payment['base_amount_authorized'], $payment['base_amount_paid']);
    }

    public function cancelingVoidsTheAuthorizedPayment(AcceptanceTester $I)
    {
        $orderId = $this->_placeAuthorizedOrder($I);

        $this->_goToTheOrder($I);
        $I->click('#order-view-cancel-button');
        $I->waitForElementClickable('.modal-popup._show .action-accept');
        $I->click('.modal-popup._show .action-accept');
        $I->waitForText('You canceled the order.', 60);

        $I->seeInDatabase('sales_order', ['entity_id' => $orderId, 'state' => 'canceled']);
        $rawDetails = json_decode($this->_payment($I, $orderId)['additional_information'], true)['raw_details_info'];
        $I->assertSame('cancelled', $rawDetails['status']);
    }

    /** config:set also cleans the config cache. */
    private function _savePaymentAction(AcceptanceTester $I, string $action): void
    {
        $this->_runMagentoCommand($I, sprintf('config:set %s %s', self::PAYMENT_ACTION_PATH, $action));
    }

    /** A guest checkout with a new card. Returns the order's entity ID once its payment is authorized, not paid. */
    private function _placeAuthorizedOrder(AcceptanceTester $I): int
    {
        $this->_doSuccessfulCheckout($I);
        $orderId = (int) $I->grabFromDatabase('sales_order', 'entity_id', ['customer_email' => $this->customerEmail]);
        $payment = $this->_payment($I, $orderId);
        $I->assertGreaterThan(0, (float) $payment['base_amount_authorized']);
        $I->assertNull($payment['base_amount_paid']);
        return $orderId;
    }

    /** The order grid lists the newest order first, which is the one this test placed. */
    private function _goToTheOrder(AcceptanceTester $I): void
    {
        $this->_goToOrderDetail($I);
        $I->see($this->customerEmail);
    }

    private function _payment(AcceptanceTester $I, int $orderId): array
    {
        return $I->grabEntryFromDatabase('sales_order_payment', ['parent_id' => $orderId]);
    }
}
