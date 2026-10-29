<?php

namespace PublicSquare\Payments\Test\Unit\Gateway\Command;

use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Gateway\Command\AuthorizeCommand;
use PublicSquare\Payments\Gateway\Command\CaptureCommand;

/**
 * Magento's vault method runs the provider's "vault_authorize" (payment action Authorize Only) or "vault_sale"
 * (Authorize & Capture) command for a saved card. A missing command fails the order with
 * "Command vault_authorize does not exist", so di.xml must register both.
 */
class CommandPoolTest extends TestCase
{
    public function testRegistersTheCommandsMagentosVaultMethodCalls(): void
    {
        $commands = [];
        $items = simplexml_load_file(dirname(__DIR__, 4) . '/PublicSquare/Payments/etc/di.xml')->xpath(
            '//virtualType[@name="PublicSquarePaymentsCommandPool"]/arguments/argument[@name="commands"]/item'
        );
        foreach ($items as $item) {
            $commands[(string) $item['name']] = trim((string) $item);
        }

        $this->assertSame(AuthorizeCommand::class, $commands['vault_authorize'] ?? null);
        $this->assertSame(CaptureCommand::class, $commands['vault_sale'] ?? null);
    }
}
