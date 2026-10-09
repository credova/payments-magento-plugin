<?php

/**
 * PublicSquareFinancial
 *
 * @category  PublicSquare
 * @package   PublicSquare_Financial
 * @author    PublicSquare <info@publicsquare.com>
 * @copyright 2019 PublicSquare
 * @license   http://opensource.org/licenses/osl-3.0.php (OSL 3.0)
 * @link      https://publicsquare.com/
 */

namespace PublicSquare\Payments\Model;

use PublicSquare\Payments\Helper\Config;
use Magento\Framework\UrlInterface;

class ConfigProvider implements \Magento\Checkout\Model\ConfigProviderInterface
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $checkoutSession;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var Config
     */
    private $publicsquareConfig;

    /**
     * Injected url builder
     *
     * @var \Magento\Framework\UrlInterface
     */
    protected $urlBuilder;

    /**
     * @param \Magento\Checkout\Model\Session            $checkoutSession
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     * @param Config                                     $publicsquareConfig
     */
    public function __construct(
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        Config $publicsquareConfig,
        UrlInterface $urlInterface
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->publicsquareConfig   = $publicsquareConfig;
        $this->urlBuilder      = $urlInterface;
    }

    public function getConfig()
    {
        return [
            'payment' => [
                $this->publicsquareConfig::CODE => [
                    'pk'         => $this->publicsquareConfig->getPublicAPIKey(),
                    // 'cancelUrl'     => $this->urlBuilder->getUrl('checkout/onepage/cancel', ['_secure' => true]),
                    'successUrl'    => $this->urlBuilder->getUrl('checkout/onepage/success', ['_secure' => true]),
                    'ccVaultCode'   => Config::VAULT_CODE,
                    'cardImagesBasePath' => Config::PUBLICSQUARE_CARD_IMAGES_BASE_PATH,
                    'cardInputCustomization' => $this->publicsquareConfig->getCardInputCustomizationJSON(),
                    'requireCvvForNewShippingAddress' => $this->publicsquareConfig->isCvvRecollectionEnabled()
                ],
            ]
        ];
    }
}
