<?php
/**
 * Config helper
 *
 * @category  PublicSquare
 * @package   PublicSquare_Payments
 * @author    PublicSquare <info@publicsquare.com>
 * @copyright 2024 PublicSquare
 * @license   http://opensource.org/licenses/osl-3.0.php (OSL 3.0)
 * @link      https://publicsquare.com/
 */

namespace PublicSquare\Payments\Helper;

use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Serialize\Serializer\Json;
use PublicSquare\Payments\ICardInputCustomizationJSON;

class Config extends AbstractHelper implements ICardInputCustomizationJSON
{
    const CODE = 'publicsquare_payments';
    const VAULT_CODE = 'publicsquare_payments_cc_vault';
    const PUBLICSQUARE_ACTIVE_CONFIG_PATH = 'payment/publicsquare_payments/active';
    const PUBLICSQUARE_TITLE_CONFIG_PATH = 'payment/publicsquare_payments/title';
    const PUBLICSQUARE_ENVIRONMENT = 'payment/publicsquare_payments/environment';
    const PUBLICSQUARE_API_BASE_URL = 'payment/publicsquare_payments/publicsquare_api_base_url';
    const PUBLICSQUARE_API_PUBLIC_KEY = 'payment/publicsquare_payments/publicsquare_api_public_key';
    const PUBLICSQUARE_API_SECRET_KEY = 'payment/publicsquare_payments/publicsquare_api_secret_key';
    const PUBLICSQUARE_SECURITY_TYPE = 'payment/publicsquare_payments/security_type';
    const PUBLICSQUARE_PRE_AUTHORIZATION_TYPE = 'payment/publicsquare_payments/pre_authorization_type';
    const PUBLICSQUARE_CVV_VERIFICATION = 'payment/publicsquare_payments/cvv_verification';
    const PUBLICSQUARE_THREE_D_SECURE_AUTHENTICATION = 'payment/publicsquare_payments/three_d_secure_authentication';
    const PUBLICSQUARE_CARD_TYPES = 'payment/publicsquare_payments/card_types';
    const PUBLICSQUARE_PAYMENT_ACTION = 'payment/publicsquare_payments/payment_action';
    const PUBLICSQUARE_LOGGING_CONFIG_PATH = 'payment/publicsquare_payments/debug';
    const PUBLICSQUARE_WEBHOOK_KEY = 'payment/publicsquare_payments/webhook_key';
    const PUBLICSQUARE_WEBHOOK_ID = 'payment/publicsquare_payments/webhook_id';
    const PUBLICSQUARE_CARD_IMAGES_BASE_PATH = 'https://assets.publicsquare.com/sc/web/assets/images/cards/';
    const PUBLICSQUARE_CUSTOMER_LOOKUP = 'payment/publicsquare_payments/customer_lookup';
    const PUBLICSQUARE_CARD_INPUT_CUSTOMIZATION = 'payment/publicsquare_payments/card_input_customization';
    const DYN_DESC_ENABLED = 'payment/publicsquare_payments/dynamic_descriptor_enabled';
    const DYN_DESC_MERCHANT = 'payment/publicsquare_payments/dynamic_descriptor_merchant';
    const DYN_DESC_MERCHANT_CONTACT = 'payment/publicsquare_payments/dynamic_descriptor_merchant_contact';
    const PUBLICSQUARE_REQUIRE_CVV_NEW_SHIPPING_ADDRESS = 'payment/publicsquare_payments/require_cvv_new_shipping_address';
    const PUBLICSQUARE_SEND_REQUIRE_FRESH_CVC = 'payment/publicsquare_payments/send_require_fresh_cvc';
    const PUBLICSQUARE_FRESH_CVC_MAX_AGE_SECONDS = 'payment/publicsquare_payments/fresh_cvc_max_age_seconds';
    const DEFAULT_FRESH_CVC_MAX_AGE_SECONDS = 1800;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $serializer;

    /**
     * PublicSquare config constructor
     *
     * @param Json|null $serializer
     */
    public function __construct(
        Context $context,
        ?Json   $serializer = null,
    )
    {
        parent::__construct($context);
        $this->serializer = $serializer ?: \Magento\Framework\App\ObjectManager::getInstance()
            ->get(Json::class);
    }

    /**
     * Get publicsquare payment method active
     *
     * @param string $scopeType
     * @param null $scopeCode
     * @return boolean
     */
    public function getActive(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): bool
    {
        return (bool)$this->scopeConfig
            ->getValue(self::PUBLICSQUARE_ACTIVE_CONFIG_PATH, $scopeType, $scopeCode);
    } //end getPublicSquareActive()

    /**
     * Get publicsquare payment method title
     *
     * @param string $scopeType
     * @param null $scopeCode
     * @return string
     */
    public function getPublicSquareTitle(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): string
    {
        return (string)$this->scopeConfig
            ->getValue(self::PUBLICSQUARE_TITLE_CONFIG_PATH, $scopeType, $scopeCode);
    } //end getPublicSquareTitle()

    /**
     * Get public api key
     *
     * @param string $scopeType
     * @param null $scopeCode
     * @return string
     */
    public function getPublicAPIKey(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): string
    {
        return (string)$this->scopeConfig
            ->getValue(self::PUBLICSQUARE_API_PUBLIC_KEY, $scopeType, $scopeCode);
    } //end getPublicAPIKey()

    /**
     * Get secret api key
     *
     * @param string $scopeType
     * @param null $scopeCode
     * @return string
     */
    public function getSecretAPIKey(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): string
    {
        return (string)$this->scopeConfig
            ->getValue(self::PUBLICSQUARE_API_SECRET_KEY, $scopeType, $scopeCode);
    } //end getSecretAPIKey()

    /**
     * Get webhook secret
     *
     * @param string $scopeType
     * @param null $scopeCode
     * @return string
     */
    public function getWebhookKey(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): string
    {
        return (string)$this->scopeConfig
            ->getValue(self::PUBLICSQUARE_WEBHOOK_KEY, $scopeType, $scopeCode);
    }

    /**
     * Get publicsquare payment method sort order
     *
     * @param string $scopeType
     * @param null $scopeCode
     * @return string
     */
    public function getEnvironment(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): string
    {
        return $this->scopeConfig
            ->getValue(self::PUBLICSQUARE_ENVIRONMENT, $scopeType, $scopeCode);
    } //end getPublicSquareEnvironment()

    /**
     * Get publicsquare payment method debug logging enabled
     *
     * @param string $scopeType
     * @param null $scopeCode
     * @return boolean
     */
    public function getLoggingEnabled(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): bool
    {
        return (bool)$this->scopeConfig
            ->getValue(self::PUBLICSQUARE_LOGGING_CONFIG_PATH, $scopeType, $scopeCode);
    } //end getPublicSquareLoggingEnabled()

    /**
     * Get setting for customer lookup during checkout
     *
     * @param string $scopeType
     * @param null $scopeCode
     * @return boolean
     */
    public function getGuestCheckoutCustomerLookup(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): bool
    {
        return (bool)$this->scopeConfig
            ->getValue(self::PUBLICSQUARE_CUSTOMER_LOOKUP, $scopeType, $scopeCode);
    } //end getGuestCheckoutCustomerLookup()

    /**
     * Whether a saved card shipping to a new address must have its CVV re-entered
     *
     * @param string $scopeType
     * @param null $scopeCode
     * @return boolean
     */
    public function isCvvRecollectionEnabled(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): bool
    {
        return (bool)$this->scopeConfig
            ->getValue(self::PUBLICSQUARE_REQUIRE_CVV_NEW_SHIPPING_ADDRESS, $scopeType, $scopeCode);
    } //end isCvvRecollectionEnabled()

    /**
     * Whether payments ask PublicSquare to verify the CVV was re-entered (require_fresh_cvc)
     *
     * Only applies when CVV recollection itself is enabled.
     *
     * @param string $scopeType
     * @param null $scopeCode
     * @return boolean
     */
    public function isFreshCvcCheckEnabled(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): bool
    {
        return $this->isCvvRecollectionEnabled($scopeType, $scopeCode)
            && (bool)$this->scopeConfig->getValue(self::PUBLICSQUARE_SEND_REQUIRE_FRESH_CVC, $scopeType, $scopeCode);
    } //end isFreshCvcCheckEnabled()

    /**
     * How long, in seconds, a CVV request stays valid
     *
     * @param string $scopeType
     * @param null $scopeCode
     * @return int
     */
    public function getFreshCvcMaxAgeSeconds(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): int
    {
        $value = (int)$this->scopeConfig
            ->getValue(self::PUBLICSQUARE_FRESH_CVC_MAX_AGE_SECONDS, $scopeType, $scopeCode);
        return $value > 0 ? $value : self::DEFAULT_FRESH_CVC_MAX_AGE_SECONDS;
    } //end getFreshCvcMaxAgeSeconds()

    /**
     * Get publicsquare payment capture action
     *
     * @param string $scopeType
     * @param null $scopeCode
     * @return string
     */
    public function getPaymentAction(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): string
    {
        return (string)$this->scopeConfig
            ->getValue(self::PUBLICSQUARE_PAYMENT_ACTION, $scopeType, $scopeCode);
    } //end getPaymentAction()

    public function getUrii(): string
    {
        return rtrim('https://api.publicsquare.com/', '/');
    } //end getUrii()

    /**
     * Get publicsquare payment allowed currencies
     *
     * @param string $scopeType
     * @param null $scopeCode
     * @return string
     */
    public function getAllowedCurrencies(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): array
    {
        return ['USD'];
    } //end getCaptureAction()

    public function getPreAuthorizationType(
        $scopeType = \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
        $scopeCode = null,
    ): string
    {
        return $this->scopeConfig
            ->getValue(self::PUBLICSQUARE_PRE_AUTHORIZATION_TYPE, $scopeType, $scopeCode);
    }

    /**
     * Return the country specific card type config
     *
     * @param int|null $storeId
     * @return array
     */
    public function getCountrySpecificCardTypeConfig($storeId = null)
    {
        return [];
    }

    /**
     * Retrieve available credit card types
     *
     * @param int|null $storeId
     * @return array
     */
    public function getAvailableCardTypes($storeId = null)
    {
        return \PublicSquare\Payments\Model\Adminhtml\Source\CcType::ALLOWED_TYPES;
    }

    /**
     * Retrieve mapper between Magento and Braintree card types
     *
     * @return array
     */
    public function getCcTypesMapper()
    {
        return [];
    }

    /**
     * Gets list of card types available for country.
     *
     * @param string $country
     * @param int|null $storeId
     * @return array
     */
    public function getCountryAvailableCardTypes($country, $storeId = null)
    {
        $types = $this->getCountrySpecificCardTypeConfig($storeId);

        return (!empty($types[$country])) ? $types[$country] : [];
    }

    public function getCardInputCustomizationJSON(): string|null
    {
        return $this->scopeConfig->getValue(self::PUBLICSQUARE_CARD_INPUT_CUSTOMIZATION);
    }

    public function isPaymentDynamicDescriptorEnabled(): bool
    {
        $enabled = $this->scopeConfig->getValue(self::DYN_DESC_ENABLED);
        // coerce to boolean
        return $enabled ? true : false;
    }

    public function getPaymentDynamicDescriptorMerchant(): string|null
    {
        return $this->scopeConfig->getValue(self::DYN_DESC_MERCHANT);
    }

    public function getPaymentDynamicDescriptorMerchantContact(): string|null
    {
        return $this->scopeConfig->getValue(self::DYN_DESC_MERCHANT_CONTACT);
    }
} //end class
