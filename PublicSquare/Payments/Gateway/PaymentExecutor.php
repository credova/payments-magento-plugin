<?php

namespace PublicSquare\Payments\Gateway;

use Magento\Sales\Model\Order\Payment\Interceptor;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Quote\Model\QuoteRepository;
use PublicSquare\Payments\Api\Authenticated\PaymentCreateFactory;
use PublicSquare\Payments\Api\Authenticated\PaymentCaptureFactory;
use PublicSquare\Payments\Api\Authenticated\PaymentCancelFactory;
use PublicSquare\Payments\Api\Authenticated\PaymentUpdateFactory;
use PublicSquare\Payments\Api\Authenticated\PaymentRefundFactory;
use PublicSquare\Payments\Api\Constants;
use PublicSquare\Payments\Logger\Logger;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Event\Observer;
use Magento\Vault\Model\CreditCardTokenFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PublicSquare\Payments\Api\Authenticated\PaymentCapture;
use PublicSquare\Payments\Helper\Config;

class PaymentExecutor
{
	/**
	 * @var QuoteRepository
	 */
	private $quoteRepository;

	/**
	 * @var PaymentCreateFactory
	 */
	private $paymentCreateFactory;

	/**
	 * @var PaymentCaptureFactory
	 */
	private $paymentCaptureFactory;

	/**
	 * @var PaymentCancelFactory
	 */
	private $paymentCancelFactory;

	/**
	 * @var PaymentUpdateFactory
	 */
	private $paymentUpdateFactory;

	/**
	 * @var PaymentRefundFactory
	 */
	private $paymentRefundFactory;

	/**
	 * @var CreditCardTokenFactory
	 */
	protected $creditCardTokenFactory;

	/**
	 * @var StoreManagerInterface
	 */
	protected $storeManager;

	/**
	 * @var PaymentTokenRepositoryInterface
	 */
	protected $paymentTokenRepository;

	/**
	 * @var PaymentTokenManagementInterface
	 */
	protected $tokenManagement;

	/**
	 * @var EncryptorInterface
	 */
	protected $encryptor;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var array
	 */
	private $_commandSubject;

	/**
	 * @var TransactionRepositoryInterface
	 */
	private $transactionRepository;

	/**
	 * @var RemoteAddress
	 */
	private $remoteAddress;

	public function __construct(
		QuoteRepository $quoteRepository,
		PaymentCreateFactory $paymentCreateFactory,
		CreditCardTokenFactory $creditCardTokenFactory,
		StoreManagerInterface $storeManager,
		PaymentTokenRepositoryInterface $paymentTokenRepository,
		PaymentTokenManagementInterface $tokenManagement,
		EncryptorInterface $encryptor,
		Logger $logger,
		TransactionRepositoryInterface $transactionRepository,
		PaymentCaptureFactory $paymentCaptureFactory,
		PaymentCancelFactory $paymentCancelFactory,
		PaymentUpdateFactory $paymentUpdateFactory,
		PaymentRefundFactory $paymentRefundFactory,
		RemoteAddress $remoteAddress
	) {
		$this->quoteRepository = $quoteRepository;
		$this->paymentCreateFactory = $paymentCreateFactory;
		$this->creditCardTokenFactory = $creditCardTokenFactory;
		$this->storeManager = $storeManager;
		$this->paymentTokenRepository = $paymentTokenRepository;
		$this->tokenManagement = $tokenManagement;
		$this->encryptor = $encryptor;
		$this->logger = $logger->withName('PSQ:PaymentExecutor');
		$this->transactionRepository = $transactionRepository;
		$this->paymentCaptureFactory = $paymentCaptureFactory;
		$this->paymentCancelFactory = $paymentCancelFactory;
		$this->paymentUpdateFactory = $paymentUpdateFactory;
		$this->paymentRefundFactory = $paymentRefundFactory;
		$this->remoteAddress = $remoteAddress;
	}

	public function setCommandSubject(array $commandSubject)
	{
		$this->_commandSubject = $commandSubject;
	}

	public function getCommandSubject()
	{
		return $this->_commandSubject;
	}

	public function executeAuthorize(array $commandSubject)
	{
		try {
			$this->createNewPayment($commandSubject, false);
		} catch (\Exception $e) {
			$this->throwUserFriendlyException($e);
		}
	}

	public function executeCapture(array $commandSubject)
	{
		try {
			$this->setCommandSubject($commandSubject);

			$payment = $this->getPayment();
			$transaction = $this->getTransaction();
			// PSQ payment id
			$transactionId = $transaction->getTxnId();
			$order = $payment->getOrder();

			$currentStatus = $payment->getAdditionalInformation("raw_details_info")["status"];

			if (!$transactionId || !str_starts_with($transactionId, "pmt_")) {
				throw new CouldNotSaveException(
					__(
						"Sorry, it is not possible to invoice this order because the payment is still pending."
					)
				);
			} elseif ($currentStatus != "requires_capture") {
				$this->logger->warning(
					sprintf(
						"PSQ Payments capture warning - Payment %s status is not 'requires_capture' (it's '%s')",
						$transactionId,
						$currentStatus
					),
					[
						"payment" => $payment->getAdditionalInformation(
							"raw_details_info"
						),
					]
				);
			} else {
				$response = $this->paymentCaptureFactory->create([
					"paymentId" => $transactionId,
					"amount" => $this->getAmount(),
					"externalId" => $order->getIncrementId() ?? ($order->getId() ?? ""),
				])->getResponseData();
				$this->setPaymentFromPSQResponse($payment, $response);
			}
		} catch (\Exception $e) {
			$this->maybeCancelPayment($commandSubject);
			$this->throwUserFriendlyException($e);
		}
	}

	public function executeAuthorizeCapture(array $commandSubject)
	{
		try {
			$this->createNewPayment($commandSubject, true);
		} catch (\Exception $e) {
			$this->throwUserFriendlyException($e);
		}
	}

	public function executeCancel(array $commandSubject)
	{
		try {
			$this->setCommandSubject($commandSubject);

			$payment = $this->getPayment();
			$transaction = $this->getTransaction();
			// PSQ payment id
			$transactionId = $transaction->getTxnId();

			if (!$transactionId) {
				throw new CouldNotSaveException(__('Sorry, it is not possible to cancel this order.'));
			}

			$response = $this->paymentCancelFactory->create([
				'paymentId' => $transactionId
			])->getResponseData();
			$payment->setAdditionalInformation(\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS, $response);
			$payment->setIsTransactionClosed(1);
		} catch (\Exception $e) {
			$this->throwUserFriendlyException($e);
		}
	}

	public function executeUpdate(Observer $observer)
	{
		$order = $observer->getEvent()->getOrder();
		$payment = $order->getPayment();
		$transactionId = $payment->getLastTransId();

		$this->logger->info("PSQ Payments update", [
			"transactionId" => $transactionId,
			"orderId" => $order->getIncrementId() ?? ($order->getId() ?? ""),
		]);

		if ($transactionId && str_starts_with($transactionId, "pmt_")) {
			$this->paymentUpdateFactory->create([
				"paymentId" => $transactionId,
				"externalId" => $order->getIncrementId() ?? ($order->getId() ?? ""),
			])->getResponse();
		}
	}

	public function executeObserverOrderDidFailToSubmit(Observer $observer)
	{
		try {
			$order = $observer->getEvent()->getOrder();
			$payment = $order->getPayment();
			$psqPaymentResponse = $payment->getAdditionalInformation(\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS);
			if (!isset($psqPaymentResponse["amount"]) || !isset($psqPaymentResponse["status"])) {
				return;
			}
			$amount = $psqPaymentResponse["amount"];
			$status = $psqPaymentResponse["status"];
			$transactionId = $payment->getLastTransId();

			if ($transactionId && str_starts_with($transactionId, "pmt_") && $amount) {
				if ($status == \PublicSquare\Payments\Api\ApiRequestAbstract::REQUIRES_CAPTURE_STATUS) {
                    $this->logger->info('PSQ Payments: Canceling payment');

                    $this->paymentCancelFactory->create([
						'paymentId' => $transactionId,
					])->getResponse();
					$payment->setIsTransactionClosed(1);
				} else {
                    $this->logger->info('Refunding payment');
                    $refundResponse = $this->paymentRefundFactory->create([
						'paymentId' => $transactionId,
						'amount' => $amount
					])->getResponseData();
                    $this->logger->info("Got refund response: ", ['id' => $refundResponse['id']]);
                    // Capture the refund id and save it in the additional data JSON column.
                    if(isset($refundResponse['id'])) {
                        $additionalInfo = $payment->getAdditionalInformation() ?? [];
                        $additionalInfo[Constants::REFUND_ID_KEY] = $refundResponse["id"];
                        $payment->setAdditionalInformation($additionalInfo);
                        $this->logger->info('Updated order payment additional info with refund id', ['refundId' => $refundResponse['id']]);
                    } else {
                        $this->logger->warning('Refund ID not present on refund API response for payment.', ['transaction_id' => $transactionId]);
                    }

                    $payment->setIsTransactionClosed(1);
				}
			}
		} catch (\Exception $e) {
			$this->logger->error("Update from observer failed", [
				"error" => $e->getMessage(),
			]);
			$this->throwUserFriendlyException($e);
		}
	}

	protected function maybeCancelPayment(array $commandSubject)
	{
		try {
			$this->executeCancel($commandSubject);
		} catch (\Exception $e) {
			$this->logger->error("Attempted to cancel payment, but failed", [
				"error" => $e->getMessage(),
			]);
		}
	}

	private function getPaymentDO()
	{
		$commandSubject = $this->getCommandSubject();
		if (!$commandSubject || !$commandSubject['payment'] instanceof \Magento\Payment\Gateway\Data\PaymentDataObjectInterface) {
			throw new \InvalidArgumentException('$commandSubject not set');
		}

		return $commandSubject['payment'];
	}

	public function getPayment()
	{
		return $this->getPaymentDO()->getPayment();
	}

	public function getOrder()
	{
		return $this->getPayment()->getOrder();
	}

	public function getQuote()
	{
		if ($order = $this->getOrder()) {
			return $this->quoteRepository->get($order->getQuoteId());
		} else {
			throw new \InvalidArgumentException('Payment or order not found');
		}
	}

	private function getAmount()
	{
		$commandSubject = $this->getCommandSubject();
		if (!$commandSubject || !isset($commandSubject['amount'])) {
			throw new \InvalidArgumentException('$commandSubject not set or amount is null');
		}

		return $this->_commandSubject['amount'];
	}

	public function getTransaction()
	{
		$tid = $this->getPayment()->getLastTransId();
		try {
			return $this->transactionRepository->get($tid);
		} catch (\Magento\Framework\Exception\InputException $e) {
			return null;
		}
	}

	public function getDeviceInformation()
	{
		$deviceInformation = [];
		if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
			// For Cloudflare proxied requests, use the IP address of the client
			$deviceInformation['ip_address'] = explode(',', $_SERVER['HTTP_CF_CONNECTING_IP'])[0];
		} else if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			// For other reverse proxies, use the IP address of the client
			$deviceInformation['ip_address'] = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
		} else if ($ip_address = $this->remoteAddress->getRemoteAddress()) {
			$deviceInformation['ip_address'] = explode(',', $ip_address)[0];
		}
		if (isset($_SERVER['HTTP_USER_AGENT'])) {
			$deviceInformation['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
		}
		return isset($deviceInformation['ip_address']) ? $deviceInformation : null;
	}

	public function getIsSaveCard(): bool
	{
		$payment = $this->getPayment();
		return $payment->getOrder()->getCustomerId() && $payment->getAdditionalInformation('saveCard');
	}

	public function createNewPayment(array $commandSubject, bool $capture)
	{
		try {
			$this->setCommandSubject($commandSubject);

			$payment = $this->getPayment();
			$quote = $this->getQuote();
			$order = $payment->getOrder();

			$billingAddress = $quote->getBillingAddress();
			$shippingAddress = $quote->getShippingAddress();
			
			$emailToUse = $order->getCustomerEmail()
			?: ($order->getBillingAddress() ? $order->getBillingAddress()->getEmail() : null);

			if (!$emailToUse) {
				$this->logger->warning(
					'PublicSquare Payments: no email found when creating payment',
					['quoteId' => $quote->getId(), 'orderId' => $order->getId()]
				);
				$this->throwUserFriendlyException(
					new \Exception('An email address is required to complete your order.')
				);
			}

			if ($quote->getIsVirtual()) {
				$shippingAddress = null;
			} else if (empty($shippingAddress->getFirstname()) || empty($shippingAddress->getLastname())) {
				$this->logger->warning('Shipping address first/last name is empty', ['quoteId' => $quote->getId(), 'quoteAddressId' => $shippingAddress->getId()]);
			}
			if ($cardId = $payment->getAdditionalInformation('cardId')) {
				$idempotencyKey = $payment->getAdditionalInformation('idempotencyKey');
				$response = $this->paymentCreateFactory->create([
					"idempotencyKey" => $idempotencyKey,
					"amount" => $this->getAmount(),
					"cardId" => $cardId,
					"capture" => $capture,
					"phone" => $billingAddress->getTelephone(),
					"email" => (string) $emailToUse,
					"shippingAddress" => $shippingAddress,
					"billingAddress" => $billingAddress,
					"externalId" => $order->getIncrementId() ?? ($order->getId() ?? ""),
					"deviceInformation" => $this->getDeviceInformation(),
					"requireFreshCvc" => $commandSubject['require_fresh_cvc'] ?? null,
				])->getResponseData();
				$this->setPaymentFromPSQResponse($payment, $response);
			} else {
				throw new \Exception('Card ID not found');
			}
		} catch (\Exception $e) {
			$this->throwUserFriendlyException($e);
		}
	}

	private function setPaymentFromPSQResponse(Interceptor $payment, $response)
	{
		$payment->setAdditionalInformation(
			\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS,
			$response
		);
		if (array_key_exists("fraud_details", $response)) {
			if (
				array_key_exists("decision", $response["fraud_details"])
			) {
				$payment->setAdditionalInformation(
					"fraud_decision",
					$response["fraud_details"]["decision"]
				);
			}
			if (array_key_exists("rules", $response["fraud_details"])) {
				$payment->setAdditionalInformation(
					"fraud_rules",
					$response["fraud_details"]["rules"]
				);
			}
		}
		if (
			array_key_exists(
				"avs_code",
				$response["payment_method"]["card"]
			)
		) {
			$payment->setAdditionalInformation(
				"avsCode",
				$response["payment_method"]["card"]["avs_code"]
			);
		}
		if (
			array_key_exists(
				"cvv2_reply",
				$response["payment_method"]["card"]
			)
		) {
			$payment->setAdditionalInformation(
				"cvv2Reply",
				$response["payment_method"]["card"]["cvv2_reply"]
			);
		}
		$payment->setLastTransId($response["id"]);
		$payment->setTransactionId($response["id"]);
		$payment->setIsTransactionClosed($response["status"] == \PublicSquare\Payments\Api\ApiRequestAbstract::REQUIRES_CAPTURE_STATUS ? 0 : 1);
		$payment->setCcLast4(
			$response["payment_method"]["card"]["last4"]
		);
		$payment->setCcType(
			$response["payment_method"]["card"]["brand"]
		);
		$payment->setCcExpMonth(
			$response["payment_method"]["card"]["exp_month"]
		);
		$payment->setCcExpYear(
			$response["payment_method"]["card"]["exp_year"]
		);
		$payment->setCcTransId($response["id"]);
		if ($this->getIsSaveCard()) {
			$this->savePaymentMethod($payment->getOrder()->getCustomerId(), $response['payment_method']);
		}
	}

	private function savePaymentMethod($customerId, $paymentMethodData)
	{
		try {
			$paymentToken = $this->creditCardTokenFactory->create();

			// Use the exp_month and exp_year to generate the expiration date
			$expirationDate = date(
				"Y-m-t 23:59:59",
				strtotime(
					$paymentMethodData["card"]["exp_year"] .
						"-" .
						$paymentMethodData["card"]["exp_month"]
				)
			);
			$paymentToken->setExpiresAt($expirationDate);
			$paymentToken->setGatewayToken($paymentMethodData["card"]["id"]);
			$paymentToken->setTokenDetails(
				json_encode([
					"type" => $paymentMethodData["card"]["brand"],
					"maskedCC" => $paymentMethodData["card"]["last4"],
					"expirationDate" =>
					$paymentMethodData["card"]["exp_month"] .
						"/" .
						$paymentMethodData["card"]["exp_year"],
				])
			);
			$paymentToken->setIsActive(true);
			$paymentToken->setIsVisible(true);
			$paymentToken->setPaymentMethodCode(Config::CODE);
			$paymentToken->setWebsiteId(
				$this->storeManager->getStore()->getWebsiteId()
			);
			$paymentToken->setCustomerId($customerId);
			$paymentToken->setPublicHash(
				$this->generatePublicHash($paymentToken)
			);
			$this->paymentTokenRepository->save($paymentToken);
		} catch (\Exception $e) {
			error_log("Error saving payment method for customer: " . $customerId);
			error_log($e->getMessage());
		}
	}

	/**
	 * Generate vault payment public hash
	 *
	 * @param PaymentTokenInterface $paymentToken
	 * @return string
	 */
	private function generatePublicHash(PaymentTokenInterface $paymentToken)
	{
		$hashKey = $paymentToken->getGatewayToken();
		if ($paymentToken->getCustomerId()) {
			$hashKey .= $paymentToken->getCustomerId();
		}

		$hashKey .=
			$paymentToken->getPaymentMethodCode() .
			$paymentToken->getType() .
			json_encode($paymentToken->getTokenDetails());

		return $this->encryptor->getHash($hashKey);
	}

	public function getCardIdFromPublicHash($publicHash, $customerId): string
	{
		$paymentToken = $this->tokenManagement->getByPublicHash(
			$publicHash,
			$customerId
		);
		return $paymentToken->getGatewayToken();
	}

	public function throwUserFriendlyException(\Exception $e)
	{
		throw new \Magento\Payment\Gateway\Command\CommandException(
			__('%1', $e->getMessage())
		);
	}
}
