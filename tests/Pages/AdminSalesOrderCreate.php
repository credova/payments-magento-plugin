<?php

namespace Tests\Pages;

use Tests\Acceptance\AcceptanceBase;
use Tests\Support\AcceptanceTester;
use Codeception\Util\Locator;

class AdminSalesOrderCreate extends AcceptanceBase
{
  protected function _goToNewSalesOrder(AcceptanceTester $I)
  {
    $this->_initialize($I);
    $this->_adminLogin($I);
    $I->amOnPage('/admin/sales/order/index/');
    $I->waitForElementClickable('.page-actions-buttons button[title="Create New Order"]');
    $I->click('.page-actions-buttons button[title="Create New Order"]');
    $I->waitForElementClickable('#edit_form .actions button[title="Create New Customer"]');
    $I->click('#edit_form .actions button[title="Create New Customer"]');
    $this->_waitForLoading($I);
    sleep(1);
  }

  /**
   * @param string|null $sku Adds this product. Null adds the last product in the search grid.
   */
  protected function _addProductToOrder(AcceptanceTester $I, ?string $sku = null)
  {
    $I->click('Add Products');
    // Magento shows the product search after its loader appears, so the loading checks alone can pass too early.
    $I->waitForElementVisible('#order-search', 60);
    $this->_waitForLoading($I);
    if ($sku !== null) {
      $I->fillField('#sales_order_create_search_grid_filter_sku', $sku);
      $I->pressKey('#sales_order_create_search_grid_filter_sku', \Facebook\WebDriver\WebDriverKeys::ENTER);
      $this->_waitForLoading($I);
      $I->waitForText($sku, 30, '#sales_order_create_search_grid_table');
    }
    $last_product = Locator::lastElement('#sales_order_create_search_grid_table>tbody>tr');
    $I->waitForElementClickable($last_product);
    $I->click($last_product);
    // A row click before the grid script is ready selects nothing, and the add then adds no item.
    $I->waitForJS(
      "return [...document.querySelectorAll('#sales_order_create_search_grid_table tbody input[type=checkbox]')].some((c) => c.checked);",
      10
    );
    $this->_waitForLoading($I);
    $I->executeJS("document.querySelector('[title=\"Add Selected Product(s) to Order\"]').click()");
    $this->_waitForLoading($I);
    $I->waitForElementNotVisible('#order-search', 30);
    $I->dontSee('No ordered items', '#order-items_grid');
  }

  protected function _addCustomerToOrder(AcceptanceTester $I)
  {
    $this->_generateUniqueEmail();
    $I->fillField('order[account][email]', $this->customerEmail);
    $I->fillField('order[billing_address][firstname]', 'Billy');
    $I->fillField('order[billing_address][lastname]', 'Bob');
    $I->fillField('order[billing_address][street][0]', '123 Main St');
    $I->selectOption('order[billing_address][country_id]', 'US');
    $I->selectOption('order[billing_address][region_id]', '15');
    $I->fillField('order[billing_address][city]', 'Newark');
    $I->fillField('order[billing_address][postcode]', '19711');
    $I->fillField('order[billing_address][telephone]', '1234567890');
  }

  protected function _addShippingMethodToOrder(AcceptanceTester $I)
  {
    $I->click('#order-shipping-method-summary>a');
    $this->_waitForLoading($I);
    $I->waitForElementClickable('#order-shipping-method-summary>a');
    $I->click('#order-shipping-method-summary>a');
    $this->_waitForLoading($I);
    $I->waitForElementClickable('#order-shipping-method-choose input');
    $I->click('#order-shipping-method-choose input');
    $this->_waitForLoading($I);
  }

  protected function _addPaymentMethodToOrder(AcceptanceTester $I, string $cardNumber = '4242424242424242')
  {
    $I->waitForElementClickable('#p_method_publicsquare_payments');
    $I->click('#p_method_publicsquare_payments');
    $this->_waitForLoading($I);
    $this->_fillCardForm($I, $cardNumber, '12/29', '123', '#publicsquare-elements-form');
  }

  protected function _submitOrder(AcceptanceTester $I, string $expectedMessage = 'You created the order.', bool $acceptPopup = false)
  {
    $I->waitForElementClickable('#edit_form #order-totals button');
    $I->click('#edit_form #order-totals button');
    if ($acceptPopup) {
      $I->acceptPopup();
    } else {
      $this->_waitForLoading($I);
      // waitForText default timeout is 10s which can be too short on slower CI runs
      $I->waitForText($expectedMessage, 30);
    }
  }
}
