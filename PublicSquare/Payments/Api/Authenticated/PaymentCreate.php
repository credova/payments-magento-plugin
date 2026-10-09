<?php
/**
 * Application
 *
 * @category  PublicSquare
 * @package   PublicSquare_Payments
 * @author    PublicSquare <info@publicsquare.com>
 * @copyright 2024 PublicSquare
 * @license   http://opensource.org/licenses/osl-3.0.php (OSL 3.0)
 * @link      https://publicsquare.com/
 */

namespace PublicSquare\Payments\Api\Authenticated;

use PublicSquare\Payments\Exception\ApiDeclinedResponseException;
use PublicSquare\Payments\Exception\ApiFailedResponseException;
use PublicSquare\Payments\Exception\ApiRejectedResponseException;
use PublicSquare\Payments\Exception\CvvRecollectionRequiredException;

class PaymentCreate extends \PublicSquare\Payments\Api\ApiRequestAbstract
{
    const PATH = "payments";

    /**
     * @var array
     */
    protected $data;

    protected $clientFactory;
    protected $configHelper;
    protected $apiHelper;
    protected $logger;

    public function __construct(
        \Laminas\Http\ClientFactory          $clientFactory,
        \PublicSquare\Payments\Helper\Config $configHelper,
        \PublicSquare\Payments\Logger\Logger $logger,
        float                                $amount,
        string                               $cardId,
        bool                                 $capture,
        string                               $phone,
        string                               $email,
        \Magento\Quote\Model\Quote\Address   $billingAddress,
                                             $shippingAddress = null,
                                             $idempotencyKey = null,
                                             $externalId = "",
                                             $deviceInformation = null,
        ?array                               $requireFreshCvc = null,
    )
    {
        parent::__construct($clientFactory, $configHelper, $logger);
        if ($idempotencyKey) {
            // Include externalId to ensure uniqueness across multishipping orders created in a single submit
            $suffix = $externalId ? ('-' . $externalId) : '';
            $this->idempotencyKey = hash('sha256', $idempotencyKey . '-' . $email . '-authorize' . $suffix);
        }
        $this->requestData = [
            "external_id" => $externalId,
            "amount" => round($amount * 100),
            "currency" => "USD",
            "capture" => $capture,
            "payment_method" => [
                "card" => $cardId,
            ],
            "customer" => [
                "external_id" => "",
                "business_name" => "",
                "first_name" => $billingAddress->getFirstname(),
                "last_name" => $billingAddress->getLastname(),
                "email" => $email,
                "phone" => $this->formatPhoneNumber($phone),
            ],
            "billing_details" => [
                "address_line_1" => $billingAddress->getStreet()[0],
                "address_line_2" => array_key_exists(
                    1,
                    $billingAddress->getStreet(),
                )
                    ? $billingAddress->getStreet()[1]
                    : "",
                "city" => $billingAddress->getCity(),
                "state" => $billingAddress->getRegionCode(),
                "postal_code" => $billingAddress->getPostcode(),
                "country" => $billingAddress->getCountryId(),
            ],
        ];
        if ($this->configHelper->isPaymentDynamicDescriptorEnabled()) {
            $merchantName = $this->configHelper->getPaymentDynamicDescriptorMerchant();
            $contact = $this->configHelper->getPaymentDynamicDescriptorMerchantContact();
            $dynamicDescriptor = [];

            if ($merchantName !== null && $merchantName != '') {
                $dynamicDescriptor['merchant_name'] = substr($merchantName, 0, min(strlen($merchantName), 22));
            }
            if ($contact !== null && $contact != '') {
                $dynamicDescriptor['merchant_contact'] = substr($contact, 0, min(strlen($contact), 13));
            }

            if (array_key_exists('merchant_name', $dynamicDescriptor)) {
                $this->requestData['dynamic_descriptor'] = $dynamicDescriptor;
            }
        }
        if ($shippingAddress) {
            $this->requestData['shipping_address'] = [
                "address_line_1" => $shippingAddress->getStreet()[0],
                "address_line_2" => array_key_exists(
                    1,
                    $shippingAddress->getStreet(),
                )
                    ? $shippingAddress->getStreet()[1]
                    : "",
                "city" => $shippingAddress->getCity(),
                "state" => $shippingAddress->getRegionCode(),
                "postal_code" => $shippingAddress->getPostcode(),
                "country" => $shippingAddress->getCountryId(),
            ];
        }
        if ($deviceInformation) {
            $this->requestData['device_information'] = $deviceInformation;
        }
        if ($requireFreshCvc) {
            // Saved card to a new address: PublicSquare rejects the payment unless the card's CVV was
            // updated after updated_after (cvv_recollection_required).
            $this->requestData['require_fresh_cvc'] = $requireFreshCvc;
        }
    } //end __construct()

    static function formatPhoneNumber(string $rawPhoneNumber): string
    {
        $phoneNumber = str_replace(" ", "-", $rawPhoneNumber);
        $phoneNumber = preg_replace("/\D+/", "", $phoneNumber);

        if (
            preg_match('/(\d{3})(\d{3})(\d{4})$/', $phoneNumber, $matches)
        ) {
            $phoneNumber =
                $matches[1] . "-" . $matches[2] . "-" . $matches[3];
        } else {
            $phoneNumber = $phoneNumber;
        }

        if (substr_count($phoneNumber, "-") == 3) {
            $phoneNumber = substr(
                $phoneNumber,
                strpos($phoneNumber, "-") + 1,
            );
        }
        return $phoneNumber;
    }

    /**
     * Get request path
     *
     * @return string
     */
    protected function getPath(): string
    {
        return static::PATH;
    } //end getPath()

    /**
     * Get request method
     *
     * @return string
     */
    protected function getMethod(): string
    {
        return \Laminas\Http\Request::METHOD_POST;
    } //end getMethod()

    protected function validateResponse(mixed $data): bool
    {
        $status = $data["status"] ?? "";

        if ($this->isCvvRecollectionRequired($data)) {
            $this->logger->warning("PSQ Payment needs the card's CVV re-entered", [
                "response" => $this->getSanitizedResponseData(),
            ]);
            throw CvvRecollectionRequiredException::create();
        }

        try {
            $this->checkResponseStatus($data);
        } catch (ApiRejectedResponseException $e) {
            $this->logger->error("PSQ Payment rejected", [
                "response" => $this->getSanitizedResponseData(),
            ]);
            if (($data["payment_method"]["card"]["cvv2_reply"] ?? null) === "N") {
                throw new ApiRejectedResponseException(
                    __("The security code didn't match. Please check it and try again."),
                );
            }
            throw new ApiRejectedResponseException(
                __(
                    "The payment could not be completed. Please verify your details and try again.",
                ),
            );
        } catch (ApiDeclinedResponseException $e) {
            $this->logger->error("PSQ Payment declined", [
                "response" => $this->getSanitizedResponseData(),
            ]);
            throw new ApiDeclinedResponseException(
                __(
                    "The payment could not be processed. Reason: " .
                    $data["declined_reason"] ??
                    "declined",
                ),
            );
        }

        if (in_array($status, [$this::SUCCEEDED_STATUS, $this::REQUIRES_CAPTURE_STATUS])) {
            $this->logger->info("PSQ Payment succeeded", [
                "response" => $this->getSanitizedResponseData(),
            ]);
            return true;
        } else {
            $this->logger->error("PSQ Payment failed", [
                "response" => $this->getSanitizedResponseData(),
            ]);
            throw new ApiFailedResponseException(
                __(
                    "The payment could not be completed. Please verify your details and try again.",
                ),
            );
        }
    } //end validateResponse()

    /**
     * Whether PublicSquare rejected the payment because the CVV wasn't updated after updated_after.
     *
     * The exact error shape isn't final, so this accepts the code at the top level or in an errors list.
     */
    private function isCvvRecollectionRequired(mixed $data): bool
    {
        if (!is_array($data)) {
            return false;
        }
        $codes = [$data["error_code"] ?? null, $data["code"] ?? null];
        foreach ((array)($data["errors"] ?? []) as $error) {
            if (is_array($error)) {
                $codes[] = $error["error_code"] ?? $error["code"] ?? null;
            }
        }
        return in_array("cvv_recollection_required", $codes, true);
    }
} //end class
