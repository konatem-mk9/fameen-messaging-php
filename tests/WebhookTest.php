<?php

declare(strict_types=1);

namespace Fameen\Messaging\Tests;

use Fameen\Messaging\Dto\WebhookEvent;
use Fameen\Messaging\Exceptions\WebhookVerificationException;
use Fameen\Messaging\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * Tests de vérification des webhooks (HMAC-SHA256 hex du corps brut).
 */
final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret_ne_pas_utiliser';

    private static function sign(string $payload, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    private static function payload(): string
    {
        return json_encode([
            'event' => 'delivered',
            'sid' => 'MSGa1b2c3',
            'status' => 'delivered',
            'channel' => 'sms',
            'to' => '+224620000000',
            'from' => 'FAMEEN',
            'error' => null,
            'externalId' => 'op-889',
            'timestamp' => '2026-07-12T10:05:00.000Z',
        ], JSON_THROW_ON_ERROR);
    }

    public function testVerifySignatureAcceptsValidSignature(): void
    {
        $payload = self::payload();

        $this->assertTrue(Webhook::verifySignature($payload, self::sign($payload), self::SECRET));
    }

    public function testVerifySignatureRejectsTamperedPayload(): void
    {
        $payload = self::payload();
        $signature = self::sign($payload);

        $this->assertFalse(Webhook::verifySignature($payload . 'x', $signature, self::SECRET), 'Corps altéré → refus.');
        $this->assertFalse(Webhook::verifySignature($payload, self::sign($payload, 'whsec_autre'), self::SECRET), 'Mauvais secret → refus.');
    }

    public function testVerifySignatureReturnsFalseWhenSignatureMissing(): void
    {
        $payload = self::payload();

        $this->assertFalse(Webhook::verifySignature($payload, null, self::SECRET));
        $this->assertFalse(Webhook::verifySignature($payload, '', self::SECRET));
    }

    public function testVerifySignatureRequiresSecret(): void
    {
        $payload = self::payload();

        $this->expectException(\InvalidArgumentException::class);

        Webhook::verifySignature($payload, self::sign($payload), '');
    }

    public function testConstructEventParsesValidEvent(): void
    {
        $payload = self::payload();

        $event = Webhook::constructEvent($payload, self::sign($payload), self::SECRET);

        $this->assertInstanceOf(WebhookEvent::class, $event);
        $this->assertSame('delivered', $event->event);
        $this->assertSame('MSGa1b2c3', $event->sid);
        $this->assertSame('delivered', $event->status);
        $this->assertSame('sms', $event->channel);
        $this->assertSame('+224620000000', $event->to);
        $this->assertSame('FAMEEN', $event->from);
        $this->assertNull($event->error);
        $this->assertSame('op-889', $event->externalId);
        $this->assertSame('2026-07-12T10:05:00.000Z', $event->timestamp);
    }

    public function testConstructEventRejectsTamperedSignature(): void
    {
        $payload = self::payload();
        $tampered = self::sign($payload . 'corruption');

        $this->expectException(WebhookVerificationException::class);

        Webhook::constructEvent($payload, $tampered, self::SECRET);
    }

    public function testConstructEventRejectsInvalidJson(): void
    {
        $invalid = '{"event": "delivered", '; // JSON tronqué mais signature VALIDE sur ces octets

        $this->expectException(WebhookVerificationException::class);
        $this->expectExceptionMessage('illisible');

        Webhook::constructEvent($invalid, self::sign($invalid), self::SECRET);
    }
}
