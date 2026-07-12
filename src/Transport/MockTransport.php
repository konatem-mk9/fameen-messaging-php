<?php

declare(strict_types=1);

namespace Fameen\Messaging\Transport;

/**
 * Transport factice pour les tests : aucun réseau, réponses (ou exceptions)
 * servies depuis une file, requêtes enregistrées pour les assertions.
 *
 * ```php
 * $transport = new MockTransport();
 * $transport->queueJson(200, ['success' => true, 'data' => [...], 'message' => 'OK']);
 * $client = new FameenMessaging(apiKey: 'fam_test', transport: $transport);
 * // … puis :
 * $transport->lastRequest(); // ['method' => 'POST', 'url' => …, 'headers' => …, 'body' => …]
 * ```
 */
final class MockTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeoutMs: int}> */
    private array $requests = [];

    /** @var list<TransportResponse|\Throwable> */
    private array $queue = [];

    private ?\Closure $handler;

    /**
     * @param callable|null $handler Optionnel : `fn (string $method, string $url, array $headers, ?string $body, int $timeoutMs): TransportResponse`.
     *                               S'il est fourni, il court-circuite la file de réponses.
     */
    public function __construct(?callable $handler = null)
    {
        $this->handler = $handler === null ? null : \Closure::fromCallable($handler);
    }

    /** Empile une réponse brute, ou un `\Throwable` à jeter (ex. ConnectionException). */
    public function queue(TransportResponse|\Throwable $response): self
    {
        $this->queue[] = $response;

        return $this;
    }

    /**
     * Empile une réponse JSON — `$body` est encodé pour vous.
     *
     * @param array<string, string> $headers En-têtes additionnels (ex. `['Retry-After' => '7']`).
     */
    public function queueJson(int $statusCode, mixed $body, array $headers = []): self
    {
        $headers = array_change_key_case($headers, CASE_LOWER) + ['content-type' => 'application/json'];

        return $this->queue(new TransportResponse($statusCode, $headers, json_encode($body, JSON_THROW_ON_ERROR)));
    }

    /**
     * {@inheritDoc}
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutMs): TransportResponse
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeoutMs' => $timeoutMs,
        ];

        if ($this->handler !== null) {
            return ($this->handler)($method, $url, $headers, $body, $timeoutMs);
        }

        if ($this->queue === []) {
            throw new \LogicException(sprintf('MockTransport : aucune réponse en file pour %s %s.', $method, $url));
        }

        $next = array_shift($this->queue);
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** @return list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeoutMs: int}> */
    public function requests(): array
    {
        return $this->requests;
    }

    /** @return array{method: string, url: string, headers: array<string, string>, body: ?string, timeoutMs: int}|null */
    public function lastRequest(): ?array
    {
        return $this->requests === [] ? null : $this->requests[array_key_last($this->requests)];
    }

    /** Nombre de requêtes reçues (tentatives incluses). */
    public function requestCount(): int
    {
        return count($this->requests);
    }
}
