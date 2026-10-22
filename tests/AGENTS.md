# Tests

- `tests/unit/`: PHPUnit (`mise run test`). `tests/js/`: Vitest (`mise run test:js`).
- `tests/Acceptance/`: Codeception against the local stack (`mise run test:acceptance`). Codeception names
  class-based tests `*Cest.php`.

## Acceptance groups

`mise run test:acceptance` skips these. Run them with `-g <group>`.

- `admin-order`: flaky on the dev stack.
- `psq-rule`: needs PublicSquare's card security code rule on the account. Until our Test account has it, the
  simulator in credova/payments-magento-plugin#29 stands in. Run it with
  `vendor/bin/codecept run tests/Acceptance/SavedCardCvvRecollectionCest.php -g psq-rule`.

The saved-card CVV tests ship each order to a new, unique address, so earlier runs don't make it a known address.
