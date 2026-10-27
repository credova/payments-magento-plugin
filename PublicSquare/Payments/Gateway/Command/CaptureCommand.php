<?php

namespace PublicSquare\Payments\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Gateway\PaymentExecutor;
use PublicSquare\Payments\Helper\Config;
use PublicSquare\Payments\Model\CardResolver;

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

    /**
     * @var CardResolver
     */
    private $cardResolver;

    public function __construct(
        Logger $logger,
        PaymentExecutor $paymentExecutor,
        CardResolver $cardResolver
    ) {
        $this->logger = $logger;
        $this->paymentExecutor = $paymentExecutor;
        $this->cardResolver = $cardResolver;
    }

    public function execute(array $commandSubject)
    {
        $payment = $commandSubject['payment']->getPayment();
        $cardId = $this->cardResolver->getCardId($payment, $payment->getOrder()->getCustomerId());
        if ($cardId) {
            $payment->setAdditionalInformation('cardId', $cardId);
        } elseif ($payment->getAdditionalInformation('public_hash') || $payment->getMethod() === Config::VAULT_CODE) {
            $this->paymentExecutor->throwUserFriendlyException(new \Exception('Card not found'));
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
