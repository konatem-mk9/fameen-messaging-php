<?php

declare(strict_types=1);

namespace Fameen\Messaging;

use Fameen\Messaging\Dto\RateLimitInfo;
use Fameen\Messaging\Exceptions\ApiException;
use Fameen\Messaging\Exceptions\ConnectionException;
use Fameen\Messaging\Resources\EmailResource;
use Fameen\Messaging\Resources\MessagesResource;
use Fameen\Messaging\Resources\OtpResource;
use Fameen\Messaging\Resources\SmsResource;
use Fameen\Messaging\Resources\WalletResource;
use Fameen\Messaging\Resources\WhatsappResource;
use Fameen\Messaging\Transport\CurlTransport;
use Fameen\Messaging\Transport\Transport;
use Fameen\Messaging\Transport\TransportResponse;

/**
 * Client de l'API Fameen Messaging.
 *
 * ```php
 * $fameen = new FameenMessaging(apiKey: getenv('FAMEEN_API_KEY'));
 * $msg = $fameen->sms()->send(['to' => '+224620000000', 'message' => 'Bonjour !']);
 * echo $msg->sid;
 * ```
 *
 * Réessais automatiques (jusqu'à `maxRetries`, backoff exponentiel + jitter) :
 * - erreur réseau → réessai (toutes méthodes) ;
 * - 429 → réessai en respectant l'en-tête `Retry-After` s'il est présent ;
 * - 5xx → réessai UNIQUEMENT si GET ou si une `idempotencyKey` est fournie
 *   (un POST non idempotent a pu être traité côté serveur → jamais réessayé).
 */
class FameenMessaging
{
    public const VERSION = '1.0.2';
    public const DEFAULT_BASE_URL = 'https://fameenbusiness.com/api/v1';

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly int $timeoutMs;
    private readonly int $maxRetries;
    private readonly int $retryBaseMs;
    private readonly Transport $transport;

    /** @var \Closure(int): void Fonction d'attente (ms) — injectable pour les tests. */
    private readonly \Closure $sleeper;

    private ?RateLimitInfo $lastRateLimit = null;

    private readonly MessagesResource $messages;
    private readonly SmsResource $sms;
    private readonly WhatsappResource $whatsapp;
    private readonly EmailResource $email;
    private readonly WalletResource $wallet;
    private readonly OtpResource $otp;

    /**
     * @param string         $apiKey      Clé API du compte (`fam_…`) — jamais côté navigateur. Requis.
     * @param string|null    $baseUrl     Défaut : `https://fameenbusiness.com/api/v1` (les `/` finaux sont retirés).
     * @param int            $timeoutMs   Timeout par tentative, en millisecondes (défaut : 30 000).
     * @param int            $maxRetries  Nombre de réessais automatiques (défaut : 2).
     * @param int            $retryBaseMs Base du backoff exponentiel en ms (défaut : 500). Surtout utile en test (mettez 1).
     * @param Transport|null $transport   Transport HTTP custom (tests, proxys). Défaut : {@see CurlTransport}.
     * @param callable|null  $sleeper     Fonction d'attente `fn (int $ms): void` — injectable pour des tests
     *                                    instantanés. Défaut : `usleep($ms * 1000)`.
     *
     * @throws \InvalidArgumentException Si `apiKey` est vide.
     */
    public function __construct(
        string $apiKey,
        ?string $baseUrl = null,
        int $timeoutMs = 30_000,
        int $maxRetries = 2,
        int $retryBaseMs = 500,
        ?Transport $transport = null,
        ?callable $sleeper = null,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('FameenMessaging : `apiKey` est requis (clé "fam_…").');
        }

        $this->apiKey = trim($apiKey);
        $this->baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->timeoutMs = max(1, $timeoutMs);
        $this->maxRetries = max(0, $maxRetries);
        $this->retryBaseMs = max(1, $retryBaseMs);
        $this->transport = $transport ?? new CurlTransport();
        $this->sleeper = $sleeper === null
            ? static function (int $ms): void {
                if ($ms > 0) {
                    usleep($ms * 1000);
                }
            }
        : \Closure::fromCallable($sleeper);

        $this->messages = new MessagesResource($this);
        $this->sms = new SmsResource($this);
        $this->whatsapp = new WhatsappResource($this);
        $this->email = new EmailResource($this);
        $this->wallet = new WalletResource($this);
        $this->otp = new OtpResource($this);
    }

    /** Ressource « Messages » unifiée : `create()`, `get()`, `list()`, `history()`. */
    public function messages(): MessagesResource
    {
        return $this->messages;
    }

    /** Envoi de SMS : `$fameen->sms()->send([...])`. */
    public function sms(): SmsResource
    {
        return $this->sms;
    }

    /** Envoi WhatsApp : `$fameen->whatsapp()->send([...])`. */
    public function whatsapp(): WhatsappResource
    {
        return $this->whatsapp;
    }

    /** Envoi d'email : `$fameen->email()->send([...])`. */
    public function email(): EmailResource
    {
        return $this->email;
    }

    /** Portefeuille : `$fameen->wallet()->balance()`. */
    public function wallet(): WalletResource
    {
        return $this->wallet;
    }

    /** Codes de verification a usage unique : `$fameen->otp()->send(...)`. */
    public function otp(): OtpResource
    {
        return $this->otp;
    }

    /**
     * Construit une pièce jointe depuis un fichier local (raccourci vers {@see Media::fromFile()}).
     *
     * ```php
     * $att = FameenMessaging::fileAttachment('facture.pdf');
     * $fameen->email()->send(['to' => 'a@b.com', 'subject' => 'Facture', 'message' => '...', 'attachments' => [$att]]);
     * ```
     *
     * @param array{filename?: string, contentType?: string, type?: string} $opts
     *
     * @return array<string, mixed>
     */
    public static function fileAttachment(string $path, array $opts = []): array
    {
        return Media::fromFile($path, $opts);
    }

    /** Compteurs `X-RateLimit-*` de la dernière réponse qui les fournissait, sinon null. */
    public function lastRateLimit(): ?RateLimitInfo
    {
        return $this->lastRateLimit;
    }

    /**
     * Exécute une requête vers l'API : enveloppe `{ success, data }` déballée,
     * erreurs typées, réessais automatiques.
     *
     * @internal Utilisé par les classes Resources — préférez `sms()`, `messages()`, etc.
     *
     * @param 'GET'|'POST'              $method
     * @param string                    $path           Chemin relatif à la base (`/messages`, `/wallet/balance`…).
     * @param array<string, mixed>      $query          Paramètres de query string (null/'' ignorés).
     * @param array<string, mixed>|null $body           Corps JSON, ou null si aucun.
     * @param string|null               $idempotencyKey En-tête `Idempotency-Key` (rend les réessais des POST sûrs).
     *
     * @return mixed Le `data` de l'enveloppe (ou le corps décodé tel quel si pas d'enveloppe).
     *
     * @throws ApiException        Réponse HTTP non-2xx.
     * @throws ConnectionException API injoignable après épuisement des réessais.
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, ?string $idempotencyKey = null): mixed
    {
        $url = $this->baseUrl . $path;
        $queryString = $this->buildQueryString($query);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'fameen-messaging-php/' . self::VERSION,
        ];
        $encodedBody = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $encodedBody = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $lastConnectionError = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            try {
                $response = $this->transport->request($method, $url, $headers, $encodedBody, $this->timeoutMs);
            } catch (ConnectionException $error) {
                // Échec réseau : la requête n'a (très probablement) pas été traitée.
                $lastConnectionError = $error;
                if ($attempt < $this->maxRetries) {
                    $this->sleep($this->backoffMs($attempt));
                    continue;
                }

                throw new ConnectionException(
                    sprintf("Impossible de joindre l'API Fameen : %s", $error->getMessage()),
                    0,
                    $error,
                );
            }

            $rateLimit = $this->readRateLimit($response);
            if ($rateLimit !== null) {
                $this->lastRateLimit = $rateLimit;
            }

            $parsed = null;
            if ($response->body !== '') {
                try {
                    $parsed = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $parsed = null;
                }
            }

            $status = $response->statusCode;

            if ($status >= 200 && $status < 300) {
                // Enveloppe standard { success, data } → on renvoie `data` directement.
                if (is_array($parsed) && array_key_exists('success', $parsed) && array_key_exists('data', $parsed)) {
                    return $parsed['data'];
                }

                return $parsed;
            }

            [$code, $message] = $this->extractError($parsed, $status, $method, $path);
            $retryAfter = $this->readRetryAfter($response);

            $retriable = $status === 429 || $status >= 500;
            // POST non idempotent : un 5xx a pu être traité côté serveur → pas de réessai.
            $safeToRetry = $method === 'GET' || ($idempotencyKey !== null && $idempotencyKey !== '') || $status === 429;

            if ($retriable && $safeToRetry && $attempt < $this->maxRetries) {
                $this->sleep($retryAfter !== null ? $retryAfter * 1000 : $this->backoffMs($attempt));
                continue;
            }

            throw new ApiException($message, $status, $code, $retryAfter, $this->lastRateLimit);
        }

        // Inatteignable (la boucle jette toujours), mais gardé par sûreté.
        throw new ConnectionException('Réessais épuisés.', 0, $lastConnectionError);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function buildQueryString(array $query): string
    {
        $filtered = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            $filtered[$key] = (string) $value;
        }

        return $filtered === [] ? '' : http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{0: string, 1: string} `[code, message]` — repli déduit du statut si le corps est illisible.
     */
    private function extractError(mixed $parsed, int $status, string $method, string $path): array
    {
        $code = null;
        $message = null;

        if (is_array($parsed)) {
            $error = $parsed['error'] ?? null;
            if (is_array($error)) {
                $code = isset($error['code']) && is_string($error['code']) && $error['code'] !== '' ? $error['code'] : null;
                $message = isset($error['message']) && is_string($error['message']) && $error['message'] !== '' ? $error['message'] : null;
            }
            if ($message === null && isset($parsed['message']) && is_string($parsed['message']) && $parsed['message'] !== '') {
                $message = $parsed['message'];
            }
        }

        return [
            $code ?? $this->codeFromStatus($status),
            $message ?? sprintf('Erreur HTTP %d sur %s %s', $status, $method, $path),
        ];
    }

    private function codeFromStatus(int $status): string
    {
        return match (true) {
            $status === 400 => 'bad_request',
            $status === 401 => 'unauthorized',
            $status === 402 => 'insufficient_credits',
            $status === 403 => 'channel_not_allowed',
            $status === 404 => 'not_found',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'internal_error',
            default => 'unknown_error',
        };
    }

    private function readRateLimit(TransportResponse $response): ?RateLimitInfo
    {
        $limit = $response->header('X-RateLimit-Limit');
        $remaining = $response->header('X-RateLimit-Remaining');
        $reset = $response->header('X-RateLimit-Reset');

        if ($limit !== null && $remaining !== null && $reset !== null
            && is_numeric($limit) && is_numeric($remaining) && is_numeric($reset)
            && (int) $limit > 0
        ) {
            return new RateLimitInfo((int) $limit, (int) $remaining, (int) $reset);
        }

        return null;
    }

    private function readRetryAfter(TransportResponse $response): ?int
    {
        $value = $response->header('Retry-After');

        return $value !== null && is_numeric($value) && (float) $value >= 0 ? (int) $value : null;
    }

    /** Backoff exponentiel : `base × 2^tentative + jitter(0..base)`. */
    private function backoffMs(int $attempt): int
    {
        $base = $this->retryBaseMs * (2 ** $attempt);

        return $base + random_int(0, max(0, $this->retryBaseMs - 1));
    }

    private function sleep(int $ms): void
    {
        ($this->sleeper)(max(0, $ms));
    }
}
