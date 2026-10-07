# Unit Testing Guide

This document explains the unit testing setup for the PublicSquare Magento Payments Plugin.

## Overview

Our unit testing approach allows you to test business logic without requiring a full Magento installation or Docker
setup. Tests run in milliseconds and can be executed locally with minimal dependencies.

## Architecture

### Key Components

1. **PHPUnit 10.0** - Modern testing framework
2. **Magento Stubs** - Minimal class definitions for Magento dependencies
3. **Composer Autoloading** - Automatic loading of stubs via `autoload-dev`
4. **Mocking** - PHPUnit's built-in `createMock()` for dependencies

### File Structure

```text
tests/unit/
├── bootstrap.php                    # Minimal bootstrap (just autoload)
├── phpunit.xml                      # PHPUnit configuration
├── Helper/
│   └── ConfigTest.php              # Example unit test
└── stubs/                          # Magento class stubs
    └── Magento/
        ├── Framework/
        │   ├── App/
        │   │   ├── Helper/
        │   │   │   ├── Context.php
        │   │   │   └── AbstractHelper.php
        │   │   └── ObjectManager.php
        │   └── Serialize/
        │       └── Serializer/
        │           └── Json.php
        └── Store/
            └── Model/
                └── ScopeInterface.php
```

## Setup

### Prerequisites

- PHP 8.3.30 and Composer 2.9.7, installed by `mise install` (see
  [LOCAL_DEVELOPMENT.md](LOCAL_DEVELOPMENT.md#toolchain))
- No Magento installation required

### Installation

1. **Install PHP, Composer, and dependencies:**

   ```bash
   mise install
   mise run composer:install
   ```

2. **Verify setup:**

   ```bash
   mise run test:unit
   ```

## Usage

### Running Tests

```bash
# Run all unit tests
mise run test:unit

# Run with verbose (testdox) output
mise run test

# Pass extra PHPUnit arguments after --
mise run test -- --filter testGetAllowedCurrenciesReturnsUSD

# Run specific test file
./vendor/bin/phpunit -c tests/unit/phpunit.xml tests/unit/Helper/ConfigTest.php

# Run specific test method
./vendor/bin/phpunit -c tests/unit/phpunit.xml --filter testGetAllowedCurrenciesReturnsUSD
```

## Writing Tests

### Basic Test Structure

```php
<?php

namespace PublicSquare\Payments\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Helper\Config;

class ConfigTest extends TestCase
{
    public function testMethodName(): void
    {
        // Arrange
        $contextMock = $this->createMock(\Magento\Framework\App\Helper\Context::class);
        
        // Act
        $config = new Config($contextMock);
        $result = $config->someMethod();
        
        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

### Mocking Dependencies

```php
public function testWithMockedDependency(): void
{
    // Create mock for Magento dependency
    $scopeConfigMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
    
    // Configure mock behavior
    $scopeConfigMock->expects($this->once())
        ->method('getValue')
        ->with('some/config/path')
        ->willReturn('expected_value');
    
    // Use mock in test
    $contextMock = $this->createMock(\Magento\Framework\App\Helper\Context::class);
    $contextMock->method('getScopeConfig')->willReturn($scopeConfigMock);
    
    $config = new Config($contextMock);
    $result = $config->getSomeConfigValue();
    
    $this->assertEquals('expected_value', $result);
}
```

## Configuration

### Composer Configuration

The `composer.json` includes:

```json
{
  "autoload": {
    "psr-4": {
      "PublicSquare\\Payments\\": "PublicSquare/Payments/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "PublicSquare\\Payments\\Test\\Unit\\": "tests/unit/",
      "Magento\\": "tests/unit/stubs/Magento/"
    }
  },
  "require-dev": {
    "phpunit/phpunit": "^10.0"
  }
}
```

### PHPUnit Configuration

The `tests/unit/phpunit.xml` provides:

- Test suite configuration
- Error reporting settings
- Memory limit settings

## Magento Stubs

### Purpose

Magento stubs provide minimal class definitions that allow PHPUnit to create mocks without requiring the full Magento
framework.

### Adding New Stubs

When you need to mock a new Magento class:

1. **Create the stub file:**

   ```text
   tests/unit/stubs/Magento/Framework/Some/NewClass.php
   ```

2. **Add minimal class definition:**

   ```php
   <?php
   
   namespace Magento\Framework\Some;
   
   class NewClass
   {
       // Minimal implementation
   }
   ```

3. **Use in tests:**

   ```php
   $mock = $this->createMock(\Magento\Framework\Some\NewClass::class);
   ```

### Current Stubs

- `Magento\Framework\App\Helper\Context` - Context for helpers
- `Magento\Framework\App\Helper\AbstractHelper` - Base helper class
- `Magento\Framework\App\ObjectManager` - Dependency injection
- `Magento\Framework\Serialize\Serializer\Json` - JSON serialization
- `Magento\Store\Model\ScopeInterface` - Scope constants

## Best Practices

### Test Organization

1. **One test class per production class**
2. **Group related tests in the same file**
3. **Use descriptive test method names**
4. **Follow AAA pattern: Arrange, Act, Assert**

### Mocking Guidelines

1. **Mock external dependencies, not the class under test**
2. **Use `createMock()` for simple mocking**
3. **Configure mock expectations explicitly**
4. **Verify mock interactions when important**

### Test Data

1. **Use meaningful test data**
2. **Test edge cases and error conditions**
3. **Keep tests independent and isolated**
4. **Avoid testing implementation details**

## Troubleshooting

### Common Issues

1. **"Class not found" errors:**
   - Ensure the class is in the autoloader
   - Check namespace and file path match
   - Run `composer dump-autoload`

2. **Mock creation failures:**
   - Verify the class exists (check stubs)
   - Ensure proper namespace usage
   - Check for typos in class names

3. **Test failures:**
   - Check expected vs actual values
   - Verify mock configurations
   - Review test logic and assertions

### Debugging

```bash
# Run with debug output
./vendor/bin/phpunit -c tests/unit/phpunit.xml --debug

# Run single test with verbose output
./vendor/bin/phpunit -c tests/unit/phpunit.xml --testdox --filter testMethodName
```

## Integration with CI/CD

### GitHub Actions

`.github/workflows/pull-request.yml` runs `mise run test` through the shared PHP workflow.

### Local Development

```bash
# Before committing
mise run test:unit

# Continuous testing (if using file watchers)
fswatch -o tests/unit/ | xargs -n1 -I{} mise run test:unit
```

## Examples

### Testing Configuration Methods

```php
public function testGetActiveReturnsTrueWhenEnabled(): void
{
    $scopeConfigMock = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
    $scopeConfigMock->expects($this->once())
        ->method('getValue')
        ->with(Config::PUBLICSQUARE_ACTIVE_CONFIG_PATH)
        ->willReturn('1');

    $contextMock = $this->createMock(\Magento\Framework\App\Helper\Context::class);
    $contextMock->method('getScopeConfig')->willReturn($scopeConfigMock);

    $config = new Config($contextMock);
    $result = $config->getActive();

    $this->assertTrue($result);
}
```

### Testing API Logic

```php
public function testApiRequestValidation(): void
{
    $apiRequest = new ApiRequestAbstract();
    
    // Test validation logic
    $this->expectException(\InvalidArgumentException::class);
    $apiRequest->validateRequest([]);
}
```

## Conclusion

This unit testing setup provides a solid foundation for testing the PublicSquare Payments plugin. It's fast, reliable,
and doesn't require complex infrastructure. The stub-based approach allows for comprehensive testing while maintaining
simplicity and maintainability.

For questions or improvements, refer to the PHPUnit documentation or the project's testing guidelines.
