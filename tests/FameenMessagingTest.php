<?php

declare(strict_types=1);

namespace Fameen\Messaging\Tests;

use Fameen\Messaging\Dto\MessageResource;
use Fameen\Messaging\Exceptions\ApiException;
use Fameen\Messaging\Exceptions\ConnectionException;
use Fameen\Messaging\FameenMessaging;
use Fameen\Messaging\Transport\MockTransport;
use Fameen\Messaging\Transport\TransportResponse;
use PHPUnit\Framework\TestCase;

/**
 * Tests du client — AUCUN réseau : tout passe par MockTransport, et les
 * attentes de backoff sont capturées via le `sleeper` injecté.
 */
final class FameenMessagingTest extends TestCase
{
    /** @var list<int> Durées d'attente (ms) demandées par le client. */
    private array $sleeps = [];

    private function makeClient(MockTransport $transport, int $maxRetries = 2): FameenMessaging
    {
        $this->sleeps = [];

        return new FameenMessaging(
            apiKey: 'fam_test_0123456789',
            baseUrl: 'https://api.example.test/api/v1/', // slash final volontaire : doit être retiré
            timeoutMs: 5_000,
            maxRetries: $maxRetries,
            retryBaseMs: 1,
            transport: $transport,
            sleeper: function (int $ms): void {
                $this->sleeps[] = $ms;
            },
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function messagePayload(array $overrides = []): array
    {
        return array_merge([
            'sid' => 'MSGa1b2c3',
            'status' => 'queued',
            'channel' => 'sms',
            'to' => '+224620000000',
            'from' => 'FAMEEN',
            'body' => 'Bonjour !',
            'segments' => 1,
            'credits' => 1,
            'error' => null,
            'externalId' => null,
            'statusCallback' => null,
            'createdAt' => '2026-07-12T10:00:00.000Z',
            'sentAt' => null,
            'deliveredAt' => null,
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function envelope(array $data): array
    {
        return ['success' => true, 'data' => $data, 'message' => 'OK'];
    }

    /** Écrit un fichier temporaire portant le nom voulu et renvoie son chemin. */
    private static function tempFile(string $content, string $name): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fameen-' . uniqid('', true);
        mkdir($dir);
        $path = $dir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $content);

        return $path;
    }

    public function testApiKeyIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FameenMessaging(apiKey: '   ');
    }

    public function testUnwrapsSuccessEnvelope(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, self::envelope(self::messagePayload()));
        $client = $this->makeClient($transport);

        $msg = $client->sms()->send(['to' => '+224620000000', 'message' => 'Bonjour !']);

        $this->assertInstanceOf(MessageResource::class, $msg);
        $this->assertSame('MSGa1b2c3', $msg->sid);
        $this->assertSame('queued', $msg->status);
        $this->assertSame('sms', $msg->channel);
        $this->assertSame('FAMEEN', $msg->from);
        $this->assertSame(1, $msg->segments);
    }

    public function testReturnsBodyAsIsWithoutEnvelope(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, self::messagePayload(['sid' => 'MSGnoenv'])); // pas d'enveloppe { success, data }
        $client = $this->makeClient($transport);

        $msg = $client->messages()->get('MSGnoenv');

        $this->assertSame('MSGnoenv', $msg->sid);
    }

    public function testSendsAuthIdempotencyAndUserAgentHeaders(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, self::envelope(self::messagePayload()));
        $client = $this->makeClient($transport);

        $client->whatsapp()->send(
            ['to' => '+224620000000', 'message' => 'Commande prête.'],
            ['idempotencyKey' => 'idem-123'],
        );

        $request = $transport->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.example.test/api/v1/whatsapp/send', $request['url']);
        $this->assertSame('Bearer fam_test_0123456789', $request['headers']['Authorization']);
        $this->assertSame('fameen-messaging-php/' . FameenMessaging::VERSION, $request['headers']['User-Agent']);
        $this->assertSame('application/json', $request['headers']['Accept']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertSame('idem-123', $request['headers']['Idempotency-Key']);

        $body = json_decode((string) $request['body'], true);
        $this->assertSame(['to' => '+224620000000', 'message' => 'Commande prête.'], $body);
    }

    public function testWhatsappMediaShortcutIsBase64Encoded(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, self::envelope(self::messagePayload(['channel' => 'whatsapp'])));
        $client = $this->makeClient($transport);

        $client->whatsapp()->send([
            'to' => '+224620000000',
            'message' => 'Votre facture',
            'media' => '%PDF-1.4 hello',
            'fileName' => 'facture.pdf',
        ]);

        $body = json_decode((string) $transport->lastRequest()['body'], true);
        $this->assertSame(base64_encode('%PDF-1.4 hello'), $body['media']);
        $this->assertSame('facture.pdf', $body['fileName']);
    }

    public function testEmailAttachmentsAreEncodedAndPreserveMetadata(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, self::envelope(self::messagePayload(['channel' => 'email'])));
        $client = $this->makeClient($transport);

        $client->email()->send([
            'to' => 'a@b.com',
            'subject' => 'Docs',
            'message' => 'Voir pièces jointes',
            'attachments' => [
                ['content' => 'un', 'filename' => 'a.pdf', 'contentType' => 'application/pdf'],
                FameenMessaging::fileAttachment(self::tempFile('deux', 'b.txt')),
            ],
        ]);

        $body = json_decode((string) $transport->lastRequest()['body'], true);
        $this->assertSame(base64_encode('un'), $body['attachments'][0]['content']);
        $this->assertSame('application/pdf', $body['attachments'][0]['contentType']);
        $this->assertSame(base64_encode('deux'), $body['attachments'][1]['content']);
        $this->assertSame('b.txt', $body['attachments'][1]['filename']);
    }

    public function testEmptyMessageAllowedWithMedia(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, self::envelope(self::messagePayload(['channel' => 'whatsapp'])));
        $client = $this->makeClient($transport);

        $msg = $client->whatsapp()->send([
            'to' => '+224620000000',
            'media' => 'octets-image',
            'mediaType' => 'image',
        ]);

        $this->assertSame('MSGa1b2c3', $msg->sid);
    }

    public function testMediaRejectedOnSmsChannel(): void
    {
        $transport = new MockTransport();
        $client = $this->makeClient($transport);

        try {
            $client->sms()->send(['to' => '+224620000000', 'message' => 'x', 'media' => 'octets']);
            $this->fail('Une InvalidArgumentException était attendue.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('SMS', $e->getMessage());
        }
        $this->assertSame(0, $transport->requestCount());
    }

    public function testListBuildsQueryStringAndParsesPage(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, self::envelope([
            'data' => [self::messagePayload(['status' => 'delivered'])],
            'page' => 2,
            'limit' => 50,
            'total' => 120,
            'totalPages' => 3,
        ]));
        $client = $this->makeClient($transport);

        $list = $client->messages()->list([
            'channel' => 'sms',
            'status' => 'delivered',
            'to' => '+224',
            'page' => 2,
            'limit' => 50,
        ]);

        $request = $transport->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame('GET', $request['method']);
        $this->assertSame(
            'https://api.example.test/api/v1/messages?channel=sms&status=delivered&to=%2B224&page=2&limit=50',
            $request['url'],
        );
        $this->assertNull($request['body']);

        $this->assertSame(2, $list->page);
        $this->assertSame(50, $list->limit);
        $this->assertSame(120, $list->total);
        $this->assertSame(3, $list->totalPages);
        $this->assertCount(1, $list->data);
        $this->assertSame('delivered', $list->data[0]->status);
    }

    public function testListOmitsEmptyFilters(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, self::envelope(['data' => [], 'page' => 1, 'limit' => 30, 'total' => 0, 'totalPages' => 0]));
        $client = $this->makeClient($transport);

        $client->messages()->list(['channel' => null, 'status' => '', 'page' => 1]);

        $request = $transport->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame('https://api.example.test/api/v1/messages?page=1', $request['url']);
    }

    public function testError402IsTyped(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(402, [
            'success' => false,
            'error' => ['code' => 'insufficient_credits', 'message' => 'Crédits insuffisants.'],
            'statusCode' => 402,
        ]);
        $client = $this->makeClient($transport);

        try {
            $client->sms()->send(['to' => '+224620000000', 'message' => 'Bonjour !']);
            $this->fail('Une ApiException était attendue.');
        } catch (ApiException $e) {
            $this->assertSame(402, $e->getStatus());
            $this->assertSame('insufficient_credits', $e->getErrorCode());
            $this->assertSame('Crédits insuffisants.', $e->getMessage());
            $this->assertNull($e->getRetryAfter());
        }

        $this->assertSame(1, $transport->requestCount());
        $this->assertSame([], $this->sleeps);
    }

    public function testErrorCodeFallsBackFromStatusWhenBodyUnreadable(): void
    {
        $transport = new MockTransport();
        $transport->queue(new TransportResponse(404, [], 'ceci n\'est pas du JSON'));
        $client = $this->makeClient($transport);

        try {
            $client->messages()->get('MSGintrouvable');
            $this->fail('Une ApiException était attendue.');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getStatus());
            $this->assertSame('not_found', $e->getErrorCode());
            $this->assertStringContainsString('Erreur HTTP 404', $e->getMessage());
        }
    }

    public function testRetries429RespectingRetryAfter(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(
            429,
            ['success' => false, 'error' => ['code' => 'rate_limited', 'message' => 'Trop de requêtes.'], 'statusCode' => 429],
            [
                'Retry-After' => '7',
                'X-RateLimit-Limit' => '60',
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => '1760000000',
            ],
        );
        $transport->queueJson(200, self::envelope(self::messagePayload(['sid' => 'MSGretry'])));
        $client = $this->makeClient($transport);

        // POST sans clé d'idempotence : le 429 est réessayé quand même (jamais traité côté serveur).
        $msg = $client->sms()->send(['to' => '+224620000000', 'message' => 'Bonjour !']);

        $this->assertSame('MSGretry', $msg->sid);
        $this->assertSame(2, $transport->requestCount());
        $this->assertSame([7000], $this->sleeps, 'Le Retry-After (7 s) doit primer sur le backoff.');

        $rateLimit = $client->lastRateLimit();
        $this->assertNotNull($rateLimit);
        $this->assertSame(60, $rateLimit->limit);
        $this->assertSame(0, $rateLimit->remaining);
        $this->assertSame(1760000000, $rateLimit->reset);
    }

    public function testRateLimitExposedOnExhausted429(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(
            429,
            ['success' => false, 'error' => ['code' => 'rate_limited', 'message' => 'Trop de requêtes.'], 'statusCode' => 429],
            ['Retry-After' => '3', 'X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '1760000000'],
        );
        $client = $this->makeClient($transport, maxRetries: 0);

        try {
            $client->wallet()->balance();
            $this->fail('Une ApiException était attendue.');
        } catch (ApiException $e) {
            $this->assertSame(429, $e->getStatus());
            $this->assertSame('rate_limited', $e->getErrorCode());
            $this->assertSame(3, $e->getRetryAfter());
            $this->assertNotNull($e->getRateLimit());
            $this->assertSame(60, $e->getRateLimit()->limit);
        }
    }

    public function testDoesNotRetryPost5xxWithoutIdempotencyKey(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(500, ['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Oops'], 'statusCode' => 500]);
        $client = $this->makeClient($transport);

        try {
            $client->sms()->send(['to' => '+224620000000', 'message' => 'Bonjour !']);
            $this->fail('Une ApiException était attendue.');
        } catch (ApiException $e) {
            $this->assertSame(500, $e->getStatus());
            $this->assertSame('internal_error', $e->getErrorCode());
        }

        $this->assertSame(1, $transport->requestCount(), 'Un POST non idempotent ne doit JAMAIS être réessayé sur 5xx.');
        $this->assertSame([], $this->sleeps);
    }

    public function testRetriesPost5xxWithIdempotencyKey(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(500, ['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Oops'], 'statusCode' => 500]);
        $transport->queueJson(200, self::envelope(self::messagePayload(['sid' => 'MSGidem'])));
        $client = $this->makeClient($transport);

        $msg = $client->sms()->send(
            ['to' => '+224620000000', 'message' => 'Bonjour !'],
            ['idempotencyKey' => 'idem-500'],
        );

        $this->assertSame('MSGidem', $msg->sid);
        $this->assertSame(2, $transport->requestCount());
        $this->assertCount(1, $this->sleeps);
    }

    public function testRetriesGet5xx(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(500, ['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Oops'], 'statusCode' => 500]);
        $transport->queueJson(200, self::envelope([
            'smsCredits' => 120,
            'waCredits' => 30,
            'emailCredits' => 500,
            'billing' => ['mode' => 'prepaid', 'postpaid' => false, 'prepaidRequired' => true, 'sendingBlocked' => false],
        ]));
        $client = $this->makeClient($transport);

        $balance = $client->wallet()->balance();

        $this->assertSame(2, $transport->requestCount());
        $this->assertSame(120, $balance->smsCredits);
        $this->assertSame('prepaid', $balance->billing->mode);
        $this->assertTrue($balance->billing->prepaidRequired);
        $this->assertFalse($balance->billing->sendingBlocked);
    }

    public function testConnectionErrorIsRetriedThenSucceeds(): void
    {
        $transport = new MockTransport();
        $transport->queue(new ConnectionException('Échec réseau cURL (6) : DNS introuvable'));
        $transport->queueJson(200, self::envelope(self::messagePayload(['sid' => 'MSGnet'])));
        $client = $this->makeClient($transport);

        $msg = $client->messages()->get('MSGnet');

        $this->assertSame('MSGnet', $msg->sid);
        $this->assertSame(2, $transport->requestCount());
        $this->assertCount(1, $this->sleeps);
    }

    public function testConnectionExceptionAfterExhaustion(): void
    {
        $transport = new MockTransport();
        $cause = new ConnectionException('Échec réseau cURL (28) : timeout');
        $transport->queue($cause)->queue($cause)->queue($cause);
        $client = $this->makeClient($transport, maxRetries: 2);

        try {
            $client->messages()->get('MSGx');
            $this->fail('Une ConnectionException était attendue.');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString("Impossible de joindre l'API Fameen", $e->getMessage());
            $this->assertSame($cause, $e->getPrevious());
        }

        $this->assertSame(3, $transport->requestCount(), '1 tentative + 2 réessais.');
        $this->assertCount(2, $this->sleeps);
    }

    public function testLocalValidationRejectsEmailRecipientOnSms(): void
    {
        $transport = new MockTransport();
        $client = $this->makeClient($transport);

        try {
            $client->sms()->send(['to' => 'user@example.com', 'message' => 'Bonjour !']);
            $this->fail('Une InvalidArgumentException était attendue.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('sms', $e->getMessage());
        }

        $this->assertSame(0, $transport->requestCount(), 'La validation locale ne doit produire AUCUN appel réseau.');
    }

    public function testLocalValidationRequiresToAndMessage(): void
    {
        $transport = new MockTransport();
        $client = $this->makeClient($transport);

        try {
            $client->messages()->create(['message' => 'Sans destinataire']);
            $this->fail('`to` manquant : une InvalidArgumentException était attendue.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('`to`', $e->getMessage());
        }

        try {
            $client->messages()->create(['to' => '+224620000000', 'message' => '   ']);
            $this->fail('`message` vide : une InvalidArgumentException était attendue.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('`message`', $e->getMessage());
        }

        $this->assertSame(0, $transport->requestCount());
    }

    public function testMessagesCreatePassesChannelAndSubject(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, self::envelope(self::messagePayload(['channel' => 'email'])));
        $client = $this->makeClient($transport);

        $client->messages()->create([
            'to' => 'client@exemple.com',
            'message' => 'Bonjour {prenom}',
            'channel' => 'email',
            'subject' => 'Votre facture',
        ]);

        $request = $transport->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame('https://api.example.test/api/v1/messages', $request['url']);
        $body = json_decode((string) $request['body'], true);
        $this->assertSame('email', $body['channel']);
        $this->assertSame('Votre facture', $body['subject']);
    }

    public function testGetEncodesSidInPath(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, self::envelope(self::messagePayload()));
        $client = $this->makeClient($transport);

        $client->messages()->get('MSG 001/x');

        $request = $transport->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame('https://api.example.test/api/v1/messages/MSG%20001%2Fx', $request['url']);
    }

    public function testLastRateLimitIsNotClobberedWhenHeadersMissing(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, self::envelope(self::messagePayload()), [
            'X-RateLimit-Limit' => '60',
            'X-RateLimit-Remaining' => '58',
            'X-RateLimit-Reset' => '1760000042',
        ]);
        $transport->queueJson(200, self::envelope(self::messagePayload()));
        $client = $this->makeClient($transport);

        $client->messages()->get('MSG1');
        $first = $client->lastRateLimit();
        $this->assertNotNull($first);
        $this->assertSame(58, $first->remaining);

        $client->messages()->get('MSG2'); // réponse SANS en-têtes X-RateLimit-*
        $second = $client->lastRateLimit();
        $this->assertNotNull($second, 'lastRateLimit ne doit pas être écrasé par null.');
        $this->assertSame(58, $second->remaining);
    }

    public function testHistoryReturnsRawPage(): void
    {
        $transport = new MockTransport();
        $transport->queueJson(200, self::envelope([
            'messages' => [['id' => 1, 'canal' => 'sms']],
            'total' => 1,
            'page' => 1,
            'pages' => 1,
        ]));
        $client = $this->makeClient($transport);

        $page = $client->messages()->history(['page' => 1]);

        $request = $transport->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame('https://api.example.test/api/v1/messages/history?page=1', $request['url']);
        $this->assertSame(1, $page['total']);
        $this->assertSame([['id' => 1, 'canal' => 'sms']], $page['messages']);
    }
}
