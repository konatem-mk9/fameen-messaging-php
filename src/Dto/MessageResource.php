<?php

declare(strict_types=1);

namespace Fameen\Messaging\Dto;

/**
 * Ressource Message telle que renvoyée par l'API (`data` de l'enveloppe).
 * Les dates sont des chaînes ISO 8601.
 *
 * Cycle de vie : `queued` → `sending` → `sent` → `delivered` | `failed`.
 */
final class MessageResource
{
    use CastsFromArray;

    public function __construct(
        /** Identifiant unique du message — à conserver pour le suivi. */
        public readonly string $sid,
        /** `queued` | `sending` | `sent` | `delivered` | `failed`. */
        public readonly string $status,
        /** `sms` | `whatsapp` | `email`. */
        public readonly string $channel,
        public readonly string $to,
        /** Expéditeur effectif (sender name SMS, numéro WhatsApp, adresse email). */
        public readonly ?string $from,
        public readonly string $body,
        /** Tranches de 160 caractères (SMS) ; 1 pour WhatsApp/email. */
        public readonly int $segments,
        public readonly int|float $credits,
        public readonly ?string $error,
        /** Identifiant du message chez l'opérateur, une fois envoyé. */
        public readonly ?string $externalId,
        public readonly ?string $statusCallback,
        /** ISO 8601. */
        public readonly string $createdAt,
        public readonly ?string $sentAt,
        public readonly ?string $deliveredAt,
    ) {
    }

    /**
     * Construit le DTO depuis le tableau JSON décodé (tolérant : champs
     * inconnus ignorés, manquants → null/0/'').
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sid: self::toStr($data['sid'] ?? null),
            status: self::toStr($data['status'] ?? null),
            channel: self::toStr($data['channel'] ?? null),
            to: self::toStr($data['to'] ?? null),
            from: self::toStrOrNull($data['from'] ?? null),
            body: self::toStr($data['body'] ?? null),
            segments: self::toInt($data['segments'] ?? null),
            credits: self::toNum($data['credits'] ?? null),
            error: self::toStrOrNull($data['error'] ?? null),
            externalId: self::toStrOrNull($data['externalId'] ?? null),
            statusCallback: self::toStrOrNull($data['statusCallback'] ?? null),
            createdAt: self::toStr($data['createdAt'] ?? null),
            sentAt: self::toStrOrNull($data['sentAt'] ?? null),
            deliveredAt: self::toStrOrNull($data['deliveredAt'] ?? null),
        );
    }
}
