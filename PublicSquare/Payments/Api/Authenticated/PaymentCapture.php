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

use \PublicSquare\Payments\Exception\ApiRejectedResponseException;
use \PublicSquare\Payments\Exception\ApiDeclinedResponseException;
use \PublicSquare\Payments\Exception\ApiFailedResponseException;

class PaymentCapture extends \PublicSquare\Payments\Api\ApiRequestAbstract
{
    const PATH = 'payments/capture';

    protected $clientFactory;
    protected $configHelper;
    protected $apiHelper;
    protected $logger;

    public function __construct(
        \Laminas\Http\ClientFactory $clientFactory,
        \PublicSquare\Payments\Helper\Config $configHelper,
        \PublicSquare\Payments\Logger\Logger $logger,
        \PublicSquare\Payments\Helper\Api $apiHelper,
        float $amount,
        string $paymentId,
        string $externalId
    ) {
        parent::__construct($clientFactory, $configHelper, $logger);
        $this->requestData = [
            "amount" => round($amount * 100),
            "payment_id" => $paymentId,
            "external_id" => $externalId,
        ];
        $this->logger->info("PSQ Payment capture request", $this->requestData);
        $this->apiHelper = $apiHelper;
    }//end __construct()

    /**
     * Get request path
     *
     * @return string
     */
    protected function getPath(): string
    {
        return static::PATH;
    }//end getPath()

    /**
     * Get request method
     *
     * @return string
     */
    protected function getMethod(): string
    {
        return \Laminas\Http\Request::METHOD_POST;
    }//end getMethod()

    protected function validateResponse(mixed $data): bool
    {
        $status = $data["status"] ?? "";
        try {
            $this->checkResponseStatus($data);
        } catch (ApiRejectedResponseException $e) {
            $this->logRefusedPayment("PSQ Payment capture rejected", $data);
            throw new ApiRejectedResponseException(
                __(
                    "The payment could not be completed. Please verify your details and try again."
                )
            );
        } catch (ApiDeclinedResponseException $e) {
            $reason = $this->declinedReason($data);
            $this->logRefusedPayment("PSQ Payment capture declined: " . ($reason ?? "not provided"), $data);
            throw new ApiDeclinedResponseException(
                __("The payment could not be processed. Reason: %1", $reason ?? "declined")
            );
        } catch (ApiFailedResponseException $e) {
            $this->logRefusedPayment("PSQ Payment capture failed", $data);
            throw $e;
        }

        if (in_array($status, [$this::SUCCEEDED_STATUS, $this::REQUIRES_CAPTURE_STATUS])) {
            $this->logger->info("PSQ Payment capture succeeded", [
                "response" => $this->getSanitizedResponseData(),
            ]);
            return true;
        } else {
            $this->logger->error("PSQ Payment capture failed", [
                "response" => $this->getSanitizedResponseData(),
            ]);
            throw new ApiFailedResponseException(
                __(
                    "The payment capture could not be completed. Please verify your details and try again."
                )
            );
        }
    } //end validateResponse()
}//end class
