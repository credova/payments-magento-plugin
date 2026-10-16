<?php

namespace PublicSquare\Payments\Dev;

use PublicSquare\Payments\Api\Authenticated\PaymentCreate;
use PublicSquare\Payments\Logger\Logger;

/**
 * DEV ONLY (spike, not for merge): answers a payment that PublicSquare's card security code rule would reject the
 * way PublicSquare does, without calling it. See Dev/README.md.
 */
class SimulateCvcStepUpRulePlugin
{
    private const REJECTED = [
        'id' => 'pmt_dev_simulated',
        'status' => 'rejected',
        'declined_reason' => 'Re-enter your card security code to ship to a new address.',
        'fraud_details' => [
            'decision' => 'reject',
            'rules' => [[
                'rule_engine' => 'internal',
                'rule_id' => 'rule_dev_simulated_cvc_step_up',
                'rule_description' => 'New shipping address needs card security code (simulated)',
                'requires_action' => 'card_security_code',
            ]],
        ],
    ];

    private CvcStepUpRuleSimulator $simulator;
    private Logger $logger;
    // Response handling calls getResponseData() again (for logging); that call must not re-simulate.
    private bool $simulating = false;

    public function __construct(CvcStepUpRuleSimulator $simulator, Logger $logger)
    {
        $this->simulator = $simulator;
        $this->logger = $logger->withName('PSQ:DevCvcStepUpRule');
    }

    public function aroundGetResponseData(PaymentCreate $subject, callable $proceed)
    {
        if ($this->simulating) {
            return (new \ReflectionProperty($subject, 'responseData'))->getValue($subject);
        }
        if (!$this->simulator->isEnabled()) {
            return $proceed();
        }
        $request = (new \ReflectionProperty($subject, 'requestData'))->getValue($subject);
        $cardId = (string)($request['payment_method']['card'] ?? '');
        if (!$this->simulator->rejects($request)) {
            $result = $proceed();
            $this->simulator->recordPayment($cardId);
            return $result;
        }

        $this->logger->info('[simulated] card security code rule rejected the payment', ['card' => $cardId]);
        $this->simulator->recordReject($cardId);
        // Run the real response handling on the body PublicSquare would send.
        (new \ReflectionProperty($subject, 'response'))->setValue($subject, new \Laminas\Http\Response());
        (new \ReflectionProperty($subject, 'responseData'))->setValue($subject, self::REJECTED);
        $this->simulating = true;
        try {
            (new \ReflectionMethod($subject, 'validateResponse'))->invoke($subject, self::REJECTED);
        } finally {
            $this->simulating = false;
        }
        return self::REJECTED;
    }
}
