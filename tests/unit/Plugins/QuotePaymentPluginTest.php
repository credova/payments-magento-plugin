<?php

namespace PublicSquare\Payments\Test\Unit\Plugins;

use Magento\Quote\Model\Quote\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Logger\Logger;
use PublicSquare\Payments\Plugins\QuotePaymentPlugin;

/**
 * The checkout renderers send these fields as paymentMethod.additional_data. The gateway reads them back with
 * getAdditionalInformation(): PaymentExecutor reads cardId, idempotencyKey and saveCard, and CaptureCommand
 * reads public_hash.
 */
class QuotePaymentPluginTest extends TestCase
{
    public static function checkoutPayloads(): array
    {
        return [
            'new card' => [
                'publicsquare_payments',
                ['cardId' => 'card_1', 'idempotencyKey' => '1700000000000abc', 'saveCard' => true],
            ],
            'saved card' => [
                'publicsquare_payments_cc_vault',
                ['public_hash' => 'hash_abc', 'idempotencyKey' => '1700000000000abc'],
            ],
        ];
    }

    #[DataProvider('checkoutPayloads')]
    public function testStoresTheCheckoutFieldsForTheGateway(string $method, array $additionalData): void
    {
        $payment = new Payment();
        $data = ['method' => $method, 'additional_data' => $additionalData];

        $result = (new QuotePaymentPlugin(new Logger()))->beforeImportData($payment, $data);

        $this->assertSame($additionalData, $payment->getAdditionalInformation());
        $this->assertSame([$data], $result);
    }

    public function testLeavesThePaymentAloneWithoutAdditionalData(): void
    {
        $payment = (new Payment())->setAdditionalInformation('method_title', 'PublicSquare');

        (new QuotePaymentPlugin(new Logger()))->beforeImportData($payment, ['method' => 'checkmo']);

        $this->assertSame(['method_title' => 'PublicSquare'], $payment->getAdditionalInformation());
    }
}
