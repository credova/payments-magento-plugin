<?php

namespace PublicSquare\Payments\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use PublicSquare\Payments\Api\Authenticated\PaymentRefundFactory;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandException;
use PublicSquare\Payments\Api\Constants;

class RefundCommand implements CommandInterface
{
    /**
     * @var PaymentRefundFactory
     */
    private $paymentRefundFactory;

    /**
     * @var TransactionRepositoryInterface
     */
    private $transactionRepository;

    protected $logger;

    public function __construct(PaymentRefundFactory $paymentRefundFactory, TransactionRepositoryInterface $transactionRepository, \PublicSquare\Payments\Logger\Logger $logger)
    {
        $this->paymentRefundFactory = $paymentRefundFactory;
        $this->transactionRepository = $transactionRepository;
        $this->logger = $logger->withName('PSQ:RefundCommand');
    }

    public function execute(array $commandSubject)
    {
        $this->logger->debug('Refund command started');
        $payment = $commandSubject['payment']->getPayment();
        $order = $payment->getOrder();
        // Half up rounding matches Magento's behavior
        $amount = round($commandSubject['amount'] * 100);
        $transactionId = $this->getTransactionId($payment);

        if (!$transactionId) {
            throw new CommandException(__('Sorry, it is not possible to invoice this order because the payment is still pending.'));
        }

        try {
            $apiCall = $this->paymentRefundFactory->create([
                'paymentId' => $transactionId,
                'amount' => $amount,
                'externalId' => $order->getIncrementId() ?? ($order->getId() ?? ""),
            ]);

            $refundResponse = $apiCall->getResponseData();
            // Capture the refund id and save it in the additional data JSON column.
            if (isset($refundResponse['id'])) {
                $this->logger->info('Got refund response: ', ['id' => $refundResponse['id']]);
                $additionalInfo = $payment->getAdditionalInformation() ?? [];
                $additionalInfo[Constants::REFUND_ID_KEY] = $refundResponse["id"];
                $payment->setAdditionalInformation($additionalInfo);
                $this->logger->info('Updated order payment additional info with refund id', ['refundId' => $refundResponse['id']]);
            } else {
                $this->logger->warning('PSQ Payments: Refund ID not present on refund API response for payment.', ['transaction_id' => $transactionId]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Refund failed', ['exception' => $e]);
            throw new CommandException(__('Sorry, refund failed. '));
        }
    }

    public function getTransactionId(\Magento\Payment\Model\InfoInterface $payment)
    {
        if ($payment->getCreditmemo() && $payment->getCreditmemo()->getInvoice()) {
            $invoice = $payment->getCreditmemo()->getInvoice();
        } else {
            $invoice = null;
        }

        if ($payment->getRefundTransactionId()) {
            $transactionId = $payment->getRefundTransactionId();
        } else {
            if ($invoice && $invoice->getTransactionId()) {
                $transactionId = $invoice->getTransactionId();
            } else {
                $transactionId = $payment->getLastTransId();
            }
        }

        preg_match('/pmt_[a-zA-Z0-9]+/', $transactionId, $matches);

        if (empty($matches)) {
            throw new CommandException(__("The payment can only be refunded via the PublicSquare Dashboard. You can retry in offline mode instead."));
        }

        return $matches[0];
    }
}
