<?php

namespace PublicSquare\Payments\Test\Unit\Model\CvvRecollection;

use Magento\Checkout\Model\Session;
use PHPUnit\Framework\TestCase;
use PublicSquare\Payments\Model\CvvRecollection\CvvRequestedByPublicSquare;

class CvvRequestedByPublicSquareTest extends TestCase
{
    public function testRemembersTheCartAndCardPublicSquareAskedAbout(): void
    {
        $requests = new CvvRequestedByPublicSquare(new Session());

        $requests->remember(42, 'card_1');

        $this->assertTrue($requests->wasRequested(42, 'card_1'));
        $this->assertFalse($requests->wasRequested(42, 'card_2'));
        $this->assertFalse($requests->wasRequested(43, 'card_1'));
    }

    public function testKeepsEarlierRequests(): void
    {
        $requests = new CvvRequestedByPublicSquare(new Session());

        $requests->remember(42, 'card_1');
        $requests->remember(42, 'card_2');

        $this->assertTrue($requests->wasRequested(42, 'card_1'));
        $this->assertTrue($requests->wasRequested(42, 'card_2'));
    }
}
