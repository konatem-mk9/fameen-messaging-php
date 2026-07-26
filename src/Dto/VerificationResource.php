<?php

declare(strict_types=1);

namespace Fameen\Messaging\Dto;

/**
 * Vérification par code à usage unique (`/v1/otp/*`).
 *
 * Ne contient **jamais** le code : celui-ci est généré côté serveur et n'est
 * transmis qu'au destinataire, par le canal choisi.
 *
 * `status` ∈ `pending | approved | rejected | expired | failed | canceled`.
 * `reason` n'est renseigné que sur un `rejected` et vaut `invalid_code`,
 * `expired` ou `max_attempts`.
 */
final class VerificationResource
{
    use CastsFromArray;

    public function __construct(
        public readonly string $verificationId,
        public readonly string $status,
        public readonly string $channel,
        public readonly string $to,
        public readonly int|float $attempts,
        public readonly int|float $maxAttempts,
        public readonly int|float $attemptsRemaining,
        public readonly ?string $expiresAt,
        public readonly ?string $createdAt,
        public readonly ?string $messageSid,
        public readonly ?string $reason,
    ) {
    }

    /** `true` si le code a été validé. */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            verificationId: self::toStr($data['verificationId'] ?? null),
            status: self::toStr($data['status'] ?? null),
            channel: self::toStr($data['channel'] ?? null),
            to: self::toStr($data['to'] ?? null),
            attempts: self::toNum($data['attempts'] ?? null),
            maxAttempts: self::toNum($data['maxAttempts'] ?? null),
            attemptsRemaining: self::toNum($data['attemptsRemaining'] ?? null),
            expiresAt: self::toStrOrNull($data['expiresAt'] ?? null),
            createdAt: self::toStrOrNull($data['createdAt'] ?? null),
            messageSid: self::toStrOrNull($data['messageSid'] ?? null),
            reason: self::toStrOrNull($data['reason'] ?? null),
        );
    }
}
