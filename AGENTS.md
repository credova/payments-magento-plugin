# AGENTS.md

This file provides guidance for agentic coding assistants (like opencode) working in the PublicSquare Magento Payments Plugin repository. It includes essential commands, code style guidelines, and best practices to maintain consistency, quality, and efficiency. Follow these guidelines to ensure changes align with the project's standards and Magento 2 conventions.

## Build/Setup Commands

Follow [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md) for the toolchain, the local Magento stack, and the `mise` tasks. Run `mise tasks` to list them. There is no Makefile.

- **Pins are frozen**: Do not change the PHP or Composer versions in `mise.toml`, or `config.platform.php` in `composer.json`, until the production versions are confirmed. Do not run `composer update`.
- **`./magento-install` is runtime-only**: It contains the Magento instance that the local stack runs. **Do not edit anything under this directory.** Use it for reference only.

## Testing Commands

The project uses PHPUnit for unit tests and Codeception for acceptance/integration tests. Run tests locally before committing.

### Unit Tests
- **Run All Unit Tests**: `mise run test` - Executes PHPUnit on `tests/unit/` using `tests/unit/phpunit.xml` (testdox output). `mise run test:unit` gives compact output.
- **Extra Arguments**: Arguments after `--` go to PHPUnit, e.g. `mise run test -- --filter ConfigTest`.
- **Coverage**: Add `-- --coverage-html tests/_output/coverage` for HTML coverage reports.

### Integration/Acceptance Tests
- **Run All Integration Tests**: `mise run test:acceptance` - Executes Codeception on `tests/Acceptance/` using `codeception.yml`. The local stack must be running (see LOCAL_DEVELOPMENT.md).
- **Single Test**: `php vendor/bin/codecept run tests/Acceptance/TestFile.php:TestMethod` - Runs a specific acceptance test.
- **With Verbose/Debug**: `php vendor/bin/codecept run -v` or `php vendor/bin/codecept run --debug`.

### CI/CD
`.github/workflows/pull-request.yml` calls the shared `credova/platform-workflows` PHP workflow. It runs `mise run lint` and `mise run test` on PHP 8.3. The acceptance suite does not run in CI.

Always run `mise run lint` and `mise run test` locally before pushing.

## Linting and Code Quality

- **Lint**: `mise run lint` - Runs `lint:style` (phpcs with the Magento2 standard, `phpcs.xml`) and `lint:compat` (PHP compatibility, `phpcs-compat.xml`).
- **Fix**: `mise run lint:fix` - Fixes what phpcbf can fix.

## Code Style Guidelines

Follow PSR-12 for PHP standards. The codebase is mostly consistent; adhere to these patterns for new code.

### File Structure and Headers
- Start with `<?php` on line 1.
- Use full docblocks for classes: `@category`, `@package`, `@author`, `@copyright`, `@license`, `@link` (OSL 3.0).
- Namespace immediately after.
- No closing `?>` tags (PSR-12).

### Imports and Use Statements
- Group after namespace; separate with blank lines.
- Fully qualified names (e.g., `use Magento\Framework\UrlInterface;`).
- Use aliases for clarity (e.g., `use PublicSquare\Payments\Helper\Config as GatewayConfig;`).
- Order: Magento core, then custom; not strictly alphabetical but logical.

### Formatting and Layout
- **Indentation**: 4 spaces (no tabs).
- **Line Length**: No strict limit; aim for readability (100-120 chars).
- **Spacing**: Around operators, after commas, before/after control structures.
- **Curly Braces**: Opening on same line (e.g., `public function __construct(`).
- **Blank Lines**: Separate methods, classes, logical blocks.
- **Semicolons**: Always at statement ends.

### Types, Type Hints, and Annotations
- **Parameters/Returns**: Use type hints (e.g., `public function getConfig(string $scopeType): bool`).
- **Nullable**: `?Json $serializer = null`.
- **Mixed**: For flexible data (e.g., `mixed $data`).
- **Properties**: PHPDoc `@var` (e.g., `/** @var Context */ protected $context;`).
- **PHPDoc**: For methods: `@param Type $name Description`, `@return Type Description`. Multiline for details.

### Naming Conventions
- **Classes**: PascalCase (e.g., `ConfigProvider`).
- **Methods/Properties**: camelCase (e.g., `getConfig`, `$checkoutSession`).
- **Constants**: UPPER_SNAKE_CASE (e.g., `CODE`, `VAULT_CODE`).
- **Variables**: camelCase (e.g., `$additionalData`).
- **Files**: Match class name (e.g., `ConfigProvider.php`).

### Error Handling and Exceptions
- Use try-catch for critical ops (e.g., `try { ... } catch (\Exception $e) { return false; }`).
- Throw custom exceptions (e.g., `ApiRejectedResponseException`).
- Log with `$this->logger->error/info` (include context arrays).
- Use `LocalizedException` with translated messages: `__( "The payment could not be completed..." )`.

### Constructors and DI
- Dependency injection: Explicit assignments (e.g., `$this->checkoutSession = $checkoutSession;`).
- No constructor property promotion.

### Translatable Strings and UI Labels
- All UI labels, messages, and user-facing strings must use Magento's translation function `__()`.
- Example: `__('Payment failed')` instead of `'Payment failed'`.
- Use placeholders for dynamic content: `__('Welcome, %1', $customerName)`.
- Ensure strings are added to CSV translation files if custom.
- Avoid concatenating strings; use sprintf or placeholders.
- Test translations by switching locale in admin.
- For client-side (JavaScript), use Magento's `mage/translate` in RequireJS modules.
- Example: `define(['mage/translate'], function ($) { $('#message').text($.mage.__('Order completed')); });`

### Other Patterns
- Access modifiers: Always explicit (`public`, `private`, `protected`).
- Static methods: Sparingly (e.g., utility functions).
- Avoid inconsistencies: Match existing file styles.

### Examples
```php
<?php
/**
 * @category PublicSquare
 * @package  PublicSquare_Payments
 * @author   PublicSquare <support@publicsquare.com>
 * @copyright Copyright (c) 2024 PublicSquare
 * @license  https://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link     https://www.publicsquare.com/
 */

namespace PublicSquare\Payments\Api;

use Magento\Checkout\Model\Session;
use Magento\Framework\Exception\LocalizedException;
use PublicSquare\Payments\Helper\Config;

class PaymentCreate
{
    /** @var Session */
    protected $checkoutSession;

    public function __construct(Session $checkoutSession, Config $config)
    {
        $this->checkoutSession = $checkoutSession;
        $this->config = $config;
    }

    public function executeAuthorize(array $data): bool
    {
        try {
            // Process payment
            return true;
        } catch (\Exception $e) {
            $this->config->getLogger()->error("PSQ Payment failed", ['error' => $e->getMessage()]);
            throw new LocalizedException(__('The payment could not be completed.'));
        }
    }
}
```

## Cursor/Copilot Rules

No .cursorrules or .cursor/rules/ files found. No .github/copilot-instructions.md. Add if needed for IDE-specific guidance.

## Best Practices

- **Magento Conventions**: Follow Magento 2 docs; use DI, avoid direct model instantiation.
- **Security**: Never log secrets/keys; use Magento's encryption for sensitive data.
- **Consistency**: Match existing patterns; review PRs for style.
- **Testing**: Write tests for new features; ensure 100% coverage for critical paths.
- **Commits**: Use conventional commits (e.g., "feat: add payment method"); run tests/lints pre-commit.
- **Performance**: Avoid N+1 queries; use caching where appropriate.
- **Escaping User-Generated Content**:
  - **Server-side (PHP/PHTML)**: Always escape user-generated content before HTML output using Magento's escaper or `htmlspecialchars()`. Example: `echo $escaper->escapeHtml($userInput);` or `htmlspecialchars($userInput, ENT_QUOTES);`.
  - **PHTML Templates**: Escape variables in templates to prevent XSS. Example: `<p><?php echo $escaper->escapeHtml($userComment); ?></p>`.
  - **HTML**: For dynamic HTML insertion, escape on server-side before rendering; avoid client-side insertion of raw user data.
  - **JavaScript**: Never insert user data into HTML via `innerHTML`; use `textContent`, `setAttribute`, or libraries like DOMPurify. Example: `element.textContent = userInput;`.
  - **Knockout (KO) Templates**: Use safe bindings like `text` instead of `html` for user data; escape data in observables. Example: `<span data-bind="text: userMessage"></span>` (not `html: userMessage`).
  - Combine with input validation; test with malicious input (e.g., `<script>` tags).
- **Documentation**: Update PHPDoc for public APIs; comment complex logic.

## Unit Test Stubs for Magento Classes

When writing unit tests that require mocking Magento or third-party classes not available in the test environment, create stub interfaces/classes in `tests/unit/stubs/` following this pattern:

- **Directory Structure**: Mirror the namespace, e.g., `Magento/Framework/App/Config/ScopeConfigInterface.php`
- **Content**: Simple interface or class with method signatures used in tests. For interfaces, include only the relevant methods.
- **Autoloading**: Add to `composer.json` `autoload-dev` section to map namespaces to stub directories.
- **Example**:
  ```php
  <?php
  namespace Magento\Framework\App\Config;

  interface ScopeConfigInterface
  {
      public function getValue($path, $scopeType = null, $scopeCode = null);
  }
  ```
- **Usage**: This allows PHPUnit to mock interfaces/classes without requiring the full Magento framework in unit tests.

Update autoload mappings and regenerate autoload when adding new stubs.

For feedback or updates, see https://github.com/sst/opencode/issues.</content>
<parameter name="filePath">/Users/btilford/Projects/publicsq/payments/publicsquare-magento-payments-plugin/AGENTS.md