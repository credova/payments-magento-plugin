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

class PaymentRefund extends \PublicSquare\Payments\Api\ApiRequestAbstract
{
    const PATH = 'refunds';

    protected $clientFactory;
    protected $configHelper;
    protected $apiHelper;
    protected $logger;

    public function __construct(
        \Laminas\Http\ClientFactory $clientFactory,
        \PublicSquare\Payments\Helper\Config $configHelper,
        \PublicSquare\Payments\Logger\Logger $logger,
        float $amount,
        string $paymentId,
        string | null $externalId = null
    ) {
        parent::__construct($clientFactory, $configHelper, $logger->withName('PSQ:PaymentRefund'));
        $this->requestData = [
            "amount" => $amount,
            "payment_id" => $paymentId
        ];
        if ($externalId) {
            $this->requestData["external_id"] = $externalId;
        }
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

    /**
     * @throws ApiDeclinedResponseException
     * @throws ApiFailedResponseException
     * @throws ApiRejectedResponseException
     */
    protected function validateResponse(mixed $data): bool
    {
        $status = $data["status"] ?? "";
        
        try {
            $this->checkResponseStatus($data);
        } catch (ApiRejectedResponseException $e) {
            $this->logRefusedPayment("PSQ Refund rejected", $data);
            throw new ApiRejectedResponseException(
                __(
                    "The Refund could not be completed. Please verify your details and try again."
                )
            );
        } catch (ApiDeclinedResponseException $e) {
            $reason = $this->declinedReason($data);
            $this->logRefusedPayment("PSQ Refund declined: " . ($reason ?? "not provided"), $data);
            throw new ApiDeclinedResponseException(
                __("The Refund could not be processed. Reason: %1", $reason ?? "declined")
            );
        } catch (ApiFailedResponseException $e) {
            $this->logRefusedPayment("PSQ Refund failed", $data);
            throw $e;
        }
        if (in_array($status, [$this::SUCCEEDED_STATUS, $this::CANCELLED_STATUS])) {
            $this->logger->info("PSQ Refund succeeded", [
                "response" => $this->getSanitizedResponseData(),
            ]);
            return true;
        } else {
            $this->logger->error("PSQ Refund failed", [
                "response" => $this->getSanitizedResponseData(),
            ]);
            throw new ApiFailedResponseException(
                __(
                    "The Refund could not be completed. Please verify your details and try again."
                )
            );
        }
    } //end validateResponse()
}//end class
