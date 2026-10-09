<?php

namespace PublicSquare\Payments\Dev;

use PublicSquare\Payments\Api\Authenticated\PaymentCreate;
use PublicSquare\Payments\Logger\Logger;

/**
 * DEV ONLY (spike, not for merge): answers a payment that fails the simulated require_fresh_cvc check
 * the way payments-api would, without calling PublicSquare. See Dev/README.md.
 */
class SimulateRequireFreshCvcPlugin
{
    private FreshCvcSimulator $simulator;
    private Logger $logger;
    // Response handling calls getResponseData() again (for logging); that call must not re-simulate.
    private bool $simulating = false;

    public function __construct(FreshCvcSimulator $simulator, Logger $logger)
    {
        $this->simulator = $simulator;
        $this->logger = $logger->withName('PSQ:DevFreshCvcSimulator');
    }

    public function aroundGetResponseData(PaymentCreate $subject, callable $proceed)
    {
        if ($this->simulating) {
            return (new \ReflectionProperty($subject, 'responseData'))->getValue($subject);
        }
        $request = (new \ReflectionProperty($subject, 'requestData'))->getValue($subject);
        $requireFreshCvc = $request['require_fresh_cvc'] ?? null;
        if (!$requireFreshCvc || !$this->simulator->isEnabled()) {
            return $proceed();
        }
        $cardId = (string)($request['payment_method']['card'] ?? '');
        if ($this->simulator->isFresh($cardId, $requireFreshCvc)) {
            $this->logger->info('[simulated] require_fresh_cvc passed', ['card' => $cardId] + $requireFreshCvc);
            return $proceed();
        }

        $this->logger->info('[simulated] require_fresh_cvc failed; answering 400 cvv_recollection_required', [
            'card' => $cardId,
        ] + $requireFreshCvc);
        // Run the real response handling on the error body payments-api would send.
        $body = ['error_code' => 'cvv_recollection_required', 'message' => 'The card CVV was not updated.'];
        (new \ReflectionProperty($subject, 'response'))->setValue($subject, new \Laminas\Http\Response());
        (new \ReflectionProperty($subject, 'responseData'))->setValue($subject, $body);
        $this->simulating = true;
        try {
            (new \ReflectionMethod($subject, 'validateResponse'))->invoke($subject, $body);
        } finally {
            $this->simulating = false;
        }
        return $body;
    }
}
