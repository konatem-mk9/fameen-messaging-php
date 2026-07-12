<?php

declare(strict_types=1);

namespace Fameen\Messaging\Dto;

/**
 * Corps JSON reçu sur votre webhook de statut (voir aussi l'en-tête
 * `X-Fameen-Event`). `event` ∈ `queued` | `sent` | `delivered` | `failed`.
 */
final class WebhookEvent
{
    use CastsFromArray;

    public function __construct(
        /** `queued` | `sent` | `delivered` | `failed`. */
        public readonly string $event,
        /** Identifiant du message concerné. */
        public readonly string $sid,
        public readonly string $status,
        public readonly string $channel,
        public readonly string $to,
        public readonly ?string $from,
        public readonly ?string $error,
        public readonly ?string $externalId,
        /** ISO 8601 — date d'émission du callback. */
        public readonly string $timestamp,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            event: self::toStr($data['event'] ?? null),
            sid: self::toStr($data['sid'] ?? null),
            status: self::toStr($data['status'] ?? null),
            channel: self::toStr($data['channel'] ?? null),
            to: self::toStr($data['to'] ?? null),
            from: self::toStrOrNull($data['from'] ?? null),
            error: self::toStrOrNull($data['error'] ?? null),
            externalId: self::toStrOrNull($data['externalId'] ?? null),
            timestamp: self::toStr($data['timestamp'] ?? null),
        );
    }
}
