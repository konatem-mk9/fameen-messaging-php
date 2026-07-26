<?php

declare(strict_types=1);

namespace Fameen\Messaging\Tests;

use Fameen\Messaging\Dto\VerificationResource;
use Fameen\Messaging\FameenMessaging;
use Fameen\Messaging\Transport\MockTransport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests des codes de vérification (OTP) — aucun réseau, tout passe par
 * MockTransport.
 */
final class OtpTest extends TestCase
{
    /** @var array<string, mixed> */
    private const PENDING = [
        'verificationId' => 'ver_1',
        'status' => 'pending',
        'channel' => 'sms',
        'to' => '+224620000000',
        'attempts' => 0,
        'maxAttempts' => 5,
        'attemptsRemaining' => 5,
        'expiresAt' => '2026-07-25T23:05:00.000Z',
        'createdAt' => '2026-07-25T23:00:00.000Z',
        'messageSid' => 'msg_1',
        'champInconnu' => 'ignoré',
    ];

    private function makeClient(MockTransport $transport): FameenMessaging
    {
        return new FameenMessaging(
            apiKey: 'fam_test_0123456789',
            baseUrl: 'https://api.example.test/api/v1',
            maxRetries: 0,
            retryBaseMs: 1,
            transport: $transport,
            sleeper: static function (int $ms): void {
            },
        );
    }

    /** @return array<string, mixed> */
    private function lastBody(MockTransport $transport): array
    {
        $body = $transport->lastRequest()['body'] ?? '{}';

        return json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function envelope(mixed $data): array
    {
        return ['success' => true, 'data' => $data, 'message' => 'OK'];
    }

    // ── Envoi ───────────────────────────────────────────────────────────────

    public function testSendPosteSurOtpSend(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, $this->envelope(self::PENDING));

        $result = $this->makeClient($transport)->otp()->send('+224620000000', channel: 'sms');

        $this->assertStringEndsWith('/otp/send', $transport->lastRequest()['url']);
        $this->assertSame(['to' => '+224620000000', 'channel' => 'sms'], $this->lastBody($transport));
        $this->assertInstanceOf(VerificationResource::class, $result);
        $this->assertSame('ver_1', $result->verificationId);
        $this->assertSame('pending', $result->status);
        $this->assertSame(5, $result->attemptsRemaining);
        $this->assertFalse($result->isApproved());
    }

    public function testSendTransmetLesReglagesPonctuels(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, $this->envelope(self::PENDING));

        $this->makeClient($transport)->otp()->send(
            'client@exemple.com',
            codeLength: 8,
            ttlSeconds: 600,
            maxAttempts: 3,
            subject: 'Votre code',
        );

        $this->assertSame([
            'to' => 'client@exemple.com',
            'codeLength' => 8,
            'ttlSeconds' => 600,
            'maxAttempts' => 3,
            'subject' => 'Votre code',
        ], $this->lastBody($transport));
    }

    public function testSendAccepteUneCleDIdempotence(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, $this->envelope(self::PENDING));

        $this->makeClient($transport)->otp()->send('+224620000000', idempotencyKey: 'otp-001');

        $headers = array_change_key_case($transport->lastRequest()['headers'], CASE_LOWER);
        $this->assertSame('otp-001', $headers['idempotency-key'] ?? null);
    }

    public function testSendRefuseUnDestinataireVide(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeClient(new MockTransport())->otp()->send('   ');
    }

    public function testSendRefuseUnGabaritSansMarqueur(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeClient(new MockTransport())->otp()->send('+224620000000', template: 'Bonjour !');
    }

    public function testSendRefuseUnEmailSurCanalSms(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeClient(new MockTransport())->otp()->send('a@b.c', channel: 'sms');
    }

    // ── Vérification ────────────────────────────────────────────────────────

    public function testVerifyValideUnCodeCorrect(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, $this->envelope(['...' => null] + ['status' => 'approved'] + self::PENDING));

        $result = $this->makeClient($transport)->otp()->verify('483920', verificationId: 'ver_1');

        $this->assertStringEndsWith('/otp/verify', $transport->lastRequest()['url']);
        $this->assertSame(['code' => '483920', 'verificationId' => 'ver_1'], $this->lastBody($transport));
        $this->assertTrue($result->isApproved());
    }

    public function testVerifyNeLevePasSurCodeErrone(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, $this->envelope(
            ['status' => 'rejected', 'reason' => 'invalid_code', 'attemptsRemaining' => 4] + self::PENDING
        ));

        $result = $this->makeClient($transport)->otp()->verify('000000', verificationId: 'ver_1');

        $this->assertFalse($result->isApproved());
        $this->assertSame('rejected', $result->status);
        $this->assertSame('invalid_code', $result->reason);
        $this->assertSame(4, $result->attemptsRemaining);
    }

    public function testVerifyParDestinataire(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, $this->envelope(['status' => 'approved'] + self::PENDING));

        $this->makeClient($transport)->otp()->verify('483920', to: '+224620000000');

        $this->assertSame(['code' => '483920', 'to' => '+224620000000'], $this->lastBody($transport));
    }

    public function testVerifyExigeUnCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeClient(new MockTransport())->otp()->verify('  ', verificationId: 'ver_1');
    }

    public function testVerifyExigeUnIdentifiantOuUnDestinataire(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeClient(new MockTransport())->otp()->verify('483920');
    }

    // ── Lecture ─────────────────────────────────────────────────────────────

    public function testGetEncodeLIdentifiant(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, $this->envelope(self::PENDING));

        $this->makeClient($transport)->otp()->get('ver/1');

        $this->assertStringEndsWith('/otp/ver%2F1', $transport->lastRequest()['url']);
    }

    public function testGetExigeUnIdentifiant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeClient(new MockTransport())->otp()->get('');
    }
}
