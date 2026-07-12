<?php

declare(strict_types=1);

namespace Fameen\Messaging\Dto;

/**
 * Compteurs de limitation de débit lus sur la dernière réponse
 * (en-têtes `X-RateLimit-*` — 60 requêtes/minute/clé).
 */
final class RateLimitInfo
{
    use CastsFromArray;

    public function __construct(
        /** Plafond de requêtes par fenêtre (`X-RateLimit-Limit`). */
        public readonly int $limit,
        /** Requêtes restantes dans la fenêtre (`X-RateLimit-Remaining`). */
        public readonly int $remaining,
        /** Fin de fenêtre, epoch en secondes (`X-RateLimit-Reset`). */
        public readonly int $reset,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            limit: self::toInt($data['limit'] ?? null),
            remaining: self::toInt($data['remaining'] ?? null),
            reset: self::toInt($data['reset'] ?? null),
        );
    }
}
