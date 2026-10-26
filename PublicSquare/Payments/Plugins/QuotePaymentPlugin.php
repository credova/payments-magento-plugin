<?php

/**
 * The purpose of this plugin is to add the cardId to the additional_information array
 * so that it can be used by the gateway to process payments.
 */

namespace PublicSquare\Payments\Plugins;

use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Model\SubscriptionRenewal;

class QuotePaymentPlugin
{
    /**
     * @var Logger
     */
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param \Magento\Quote\Model\Quote\Payment $subject
     * @param array $data
     * @return array
     */
    public function beforeImportData(\Magento\Quote\Model\Quote\Payment $subject, array $data)
    {
        // Only SubscriptionRenewal::markAsRenewal() may mark a payment as a renewal, never a client.
        $subject->unsAdditionalInformation(SubscriptionRenewal::INITIAL_PAYMENT_KEY);
        if (isset($data['additional_data']) && is_array($data['additional_data'])) {
            $additionalData = $data['additional_data'];
            unset($additionalData[SubscriptionRenewal::INITIAL_PAYMENT_KEY]);
            $subject->setAdditionalInformation($additionalData);
        }
        return [$data];
    }
}
