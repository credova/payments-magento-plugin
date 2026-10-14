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
        if ($commandSubject["amount"] > 0) {
            $payment = $commandSubject['payment']->getPayment();
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
