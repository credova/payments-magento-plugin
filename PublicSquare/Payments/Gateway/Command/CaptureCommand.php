<?php

namespace PublicSquare\Payments\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Gateway\PaymentExecutor;

class CaptureCommand implements CommandInterface
{
    /**
     * @var \PublicSquare\Payments\Logger\Logger
     */
    private $logger;

    /**
     * @var \PublicSquare\Payments\Gateway\PaymentExecutor
     */
    private $paymentExecutor;

    public function __construct(
        Logger $logger,
        PaymentExecutor $paymentExecutor
    ) {
        $this->logger = $logger;
        $this->paymentExecutor = $paymentExecutor;
    }

    public function execute(array $commandSubject)
    {
        $payment = $commandSubject['payment']->getPayment();
        if ($card_id = $payment->getAdditionalInformation('payment_method_nonce')) {
            // Get payment methon nonce, which is the card id
            $payment->setAdditionalInformation('cardId', $card_id);
        } else if ($public_hash = $payment->getAdditionalInformation('public_hash')) {
            // Saved payment method
            $customerId = $payment->getOrder()->getCustomerId();
            if (!$customerId) {
                $this->paymentExecutor->throwUserFriendlyException(new \Exception('Customer not found'));
            }
            $card_id = $this->paymentExecutor->getCardIdFromPublicHash($public_hash, $customerId);
            if (!$card_id) {
                $this->paymentExecutor->throwUserFriendlyException(new \Exception('Card not found'));
            }
            $payment->setAdditionalInformation('cardId', $card_id);
        }

        if ($commandSubject["amount"] > 0) {
            $this->paymentExecutor->setCommandSubject($commandSubject);
            $transaction = $this->paymentExecutor->getTransaction();
    
            if ($transaction) {
                // Capture the payment
                $this->paymentExecutor->executeCapture($commandSubject);
            } elseif ($payment->getLastTransId()) {
                // Sent to PublicSquare already, and its authorization was captured or voided: a second partial
                // invoice, or an order from another gateway. A new payment here would charge the card again.
                $this->paymentExecutor->throwUserFriendlyException(new \Exception(
                    'This payment was already captured or canceled. Create this invoice with Capture Offline.'
                ));
            } else {
                // Authorize and capture the payment
                $this->paymentExecutor->executeAuthorizeCapture($commandSubject);
            }
        }
    }
}
