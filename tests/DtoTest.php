<?php

declare(strict_types=1);

namespace Fameen\Messaging\Tests;

use Fameen\Messaging\Dto\MessageList;
use Fameen\Messaging\Dto\MessageResource;
use Fameen\Messaging\Dto\RateLimitInfo;
use Fameen\Messaging\Dto\WalletBalance;
use Fameen\Messaging\Dto\WebhookEvent;
use PHPUnit\Framework\TestCase;

/**
 * Tests des `fromArray()` tolérants : champs inconnus ignorés,
 * manquants → null/0/''.
 */
final class DtoTest extends TestCase
{
    public function testMessageResourceFromArrayIsTolerant(): void
    {
        $msg = MessageResource::fromArray([
            'sid' => 'MSG1',
            'champInconnu' => 'ignoré',
            'credits' => '2.5',
        ]);

        $this->assertSame('MSG1', $msg->sid);
        $this->assertSame('', $msg->status);
        $this->assertNull($msg->from);
        $this->assertSame(0, $msg->segments);
        $this->assertSame(2.5, $msg->credits);
        $this->assertNull($msg->deliveredAt);
        $this->assertSame('', $msg->createdAt);
    }

    public function testMessageListFromArraySkipsInvalidRows(): void
    {
        $list = MessageList::fromArray([
            'data' => [['sid' => 'MSGa'], 'ligne-cassée', ['sid' => 'MSGb']],
            'page' => 1,
            'limit' => 30,
            'total' => 2,
            'totalPages' => 1,
        ]);

        $this->assertCount(2, $list->data);
        $this->assertSame('MSGa', $list->data[0]->sid);
        $this->assertSame('MSGb', $list->data[1]->sid);
        $this->assertSame(30, $list->limit);
    }

    public function testWalletBalanceFromArrayWithoutBillingUsesDefaults(): void
    {
        $balance = WalletBalance::fromArray(['smsCredits' => 10]);

        $this->assertSame(10, $balance->smsCredits);
        $this->assertSame(0, $balance->waCredits);
        $this->assertSame('', $balance->billing->mode);
        $this->assertFalse($balance->billing->postpaid);
        $this->assertFalse($balance->billing->sendingBlocked);
    }

    public function testWebhookEventFromArrayDefaults(): void
    {
        $event = WebhookEvent::fromArray([]);

        $this->assertSame('', $event->event);
        $this->assertSame('', $event->sid);
        $this->assertNull($event->from);
        $this->assertNull($event->externalId);
    }

    public function testRateLimitInfoFromArray(): void
    {
        $info = RateLimitInfo::fromArray(['limit' => '60', 'remaining' => 12, 'reset' => 1760000000]);

        $this->assertSame(60, $info->limit);
        $this->assertSame(12, $info->remaining);
        $this->assertSame(1760000000, $info->reset);
    }
}
