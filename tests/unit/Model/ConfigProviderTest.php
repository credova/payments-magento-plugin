<?php

namespace PublicSquare\Payments\Test\Unit\Model;

use Magento\Checkout\Model\Session;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Helper\Config;
use PublicSquare\Payments\Model\ConfigProvider;

/**
 * Magento puts this config in window.checkoutConfig. The checkout renderers read each key under
 * payment.publicsquare_payments, so a renamed key breaks checkout with no PHP error.
 */
class ConfigProviderTest extends TestCase
{
    private const RENDERERS = [
        'view/frontend/web/js/view/payment/method-renderer/publicsquare_payments-method.js',
        'view/frontend/web/js/view/payment/method-renderer/vault.js',
    ];

    public function testGivesCheckoutTheKeysTheRenderersRead(): void
    {
        $this->assertSame(
            [
                'payment' => [
                    'publicsquare_payments' => [
                        'pk' => 'pk_test_key',
                        'successUrl' => 'https://shop.test/checkout/onepage/success/',
                        'ccVaultCode' => 'publicsquare_payments_cc_vault',
                        'cardImagesBasePath' => 'https://assets.publicsquare.com/sc/web/assets/images/cards/',
                        'cardInputCustomization' => '{"theme":"dark"}',
                    ],
                ],
            ],
            $this->provider()->getConfig(),
        );
    }

    public function testEveryKeyTheRenderersReadIsProvided(): void
    {
        $provided = array_keys($this->provider()->getConfig()['payment'][Config::CODE]);
        $read = [];
        foreach (self::RENDERERS as $renderer) {
            preg_match_all('/checkoutConfig\.payment\.' . Config::CODE . '\.(\w+)/', $this->moduleFile($renderer), $m);
            $read = array_merge($read, $m[1]);
        }

        $this->assertNotEmpty($read);
        $this->assertSame([], array_values(array_diff(array_unique($read), $provided)));
    }

    public function testTheVaultCodeIsTheMethodTheSavedCardRendererRegisters(): void
    {
        $renderer = $this->moduleFile('view/frontend/web/js/view/payment/method-renderer-vault.js');
        $config = simplexml_load_string($this->moduleFile('etc/config.xml'));

        $this->assertMatchesRegularExpression('/type:\s*[\'"]' . Config::VAULT_CODE . '[\'"]/', $renderer);
        $this->assertNotEmpty($config->xpath('//default/payment/' . Config::VAULT_CODE));
    }

    private function provider(): ConfigProvider
    {
        $config = $this->createMock(Config::class);
        $config->method('getPublicAPIKey')->willReturn('pk_test_key');
        $config->method('getCardInputCustomizationJSON')->willReturn('{"theme":"dark"}');
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')
            ->with('checkout/onepage/success', ['_secure' => true])
            ->willReturn('https://shop.test/checkout/onepage/success/');

        return new ConfigProvider(
            $this->createMock(Session::class),
            $this->createMock(CartRepositoryInterface::class),
            $config,
            $urlBuilder,
        );
    }

    private function moduleFile(string $path): string
    {
        $file = dirname(__DIR__, 3) . '/PublicSquare/Payments/' . $path;
        $this->assertFileExists($file);

        return file_get_contents($file);
    }
}
