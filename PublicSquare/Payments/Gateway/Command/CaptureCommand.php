<?php

namespace PublicSquare\Payments\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Gateway\PaymentExecutor;
use PublicSquare\Payments\Model\CvvRecollection\PaymentGuard;

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
     * @var \PublicSquare\Payments\Model\CvvRecollection\PaymentGuard
     */
    private $cvvPaymentGuard;

    public function __construct(
        Logger $logger,
        PaymentExecutor $paymentExecutor,
        PaymentGuard $cvvPaymentGuard
    ) {
        $this->logger = $logger;
        $this->paymentExecutor = $paymentExecutor;
        $this->cvvPaymentGuard = $cvvPaymentGuard;
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

            // Saved card to a new shipping address: the CVV must have been re-entered (throws if it never was).
            $requireFreshCvc = $this->cvvPaymentGuard->check($payment, $public_hash);
            if ($requireFreshCvc) {
                $commandSubject['require_fresh_cvc'] = $requireFreshCvc;
            }
        }

        if ($commandSubject["amount"] > 0) {
            $this->paymentExecutor->setCommandSubject($commandSubject);
            $transaction = $this->paymentExecutor->getTransaction();
    
            if ($transaction) {
                // Capture the payment
                $this->paymentExecutor->executeCapture($commandSubject);
            } else {
                // Authorize and capture the payment
                $this->paymentExecutor->executeAuthorizeCapture($commandSubject);
            }
        }
    }
}
