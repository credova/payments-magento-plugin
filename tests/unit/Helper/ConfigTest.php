<?php

namespace PublicSquare\Payments\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Helper\Config;

class ConfigTest extends TestCase
{
    public function testGetAllowedCurrenciesReturnsUSD(): void
    {
        $contextMock = $this->createMock(\Magento\Framework\App\Helper\Context::class);
        
        $config = new Config($contextMock);
        $result = $config->getAllowedCurrencies();
        
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertEquals(['USD'], $result);
        $this->assertContains('USD', $result);
    }
    
    public function testGetUriiReturnsCorrectBaseUrl(): void
    {
        $contextMock = $this->createMock(\Magento\Framework\App\Helper\Context::class);
        
        $config = new Config($contextMock);
        $result = $config->getUrii();
        
        $this->assertIsString($result);
        $this->assertEquals('https://api.publicsquare.com', $result);
    }

    public function testCvvRecollectionIsOffWhenNotConfigured(): void
    {
        $config = $this->configWithValues([]);

        $this->assertFalse($config->isCvvRecollectionEnabled());
        $this->assertFalse($config->isFreshCvcCheckEnabled());
    }

    public function testCvvRecollectionIsOnWhenEnabled(): void
    {
        $config = $this->configWithValues([Config::PUBLICSQUARE_REQUIRE_CVV_NEW_SHIPPING_ADDRESS => '1']);

        $this->assertTrue($config->isCvvRecollectionEnabled());
        $this->assertFalse($config->isFreshCvcCheckEnabled());
    }

    public function testFreshCvcCheckNeedsCvvRecollectionEnabled(): void
    {
        $config = $this->configWithValues([Config::PUBLICSQUARE_SEND_REQUIRE_FRESH_CVC => '1']);

        $this->assertFalse($config->isFreshCvcCheckEnabled());
    }

    public function testFreshCvcCheckIsOnWhenBothSettingsAreOn(): void
    {
        $config = $this->configWithValues([
            Config::PUBLICSQUARE_REQUIRE_CVV_NEW_SHIPPING_ADDRESS => '1',
            Config::PUBLICSQUARE_SEND_REQUIRE_FRESH_CVC => '1',
        ]);

        $this->assertTrue($config->isFreshCvcCheckEnabled());
    }

    public function testFreshCvcMaxAgeUsesTheConfiguredValue(): void
    {
        $config = $this->configWithValues([Config::PUBLICSQUARE_FRESH_CVC_MAX_AGE_SECONDS => '600']);

        $this->assertSame(600, $config->getFreshCvcMaxAgeSeconds());
    }

    public function testFreshCvcMaxAgeFallsBackToTheDefaultForMissingOrInvalidValues(): void
    {
        $this->assertSame(1800, $this->configWithValues([])->getFreshCvcMaxAgeSeconds());
        $this->assertSame(
            1800,
            $this->configWithValues([Config::PUBLICSQUARE_FRESH_CVC_MAX_AGE_SECONDS => '0'])->getFreshCvcMaxAgeSeconds()
        );
    }

    /**
     * @param array<string, string> $values Config values by path; other paths return null.
     */
    private function configWithValues(array $values): Config
    {
        $scopeConfig = $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn ($path) => $values[$path] ?? null);
        $context = $this->createMock(\Magento\Framework\App\Helper\Context::class);
        $context->method('getScopeConfig')->willReturn($scopeConfig);

        return new Config($context);
    }
}
