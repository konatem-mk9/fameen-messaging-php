<?php

declare(strict_types=1);

namespace Fameen\Messaging\Dto;

/**
 * Mode de facturation du compte (sous-objet `billing` de
 * {@see WalletBalance}).
 */
final class BillingInfo
{
    use CastsFromArray;

    public function __construct(
        /** `prepaid` | `consumption`. */
        public readonly string $mode,
        /** `true` = facturation à la consommation : l'envoi n'est pas limité par le solde. */
        public readonly bool $postpaid,
        public readonly bool $prepaidRequired,
        /** `true` = compte bloqué (période de consommation expirée). */
        public readonly bool $sendingBlocked,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            mode: self::toStr($data['mode'] ?? null),
            postpaid: self::toBool($data['postpaid'] ?? null),
            prepaidRequired: self::toBool($data['prepaidRequired'] ?? null),
            sendingBlocked: self::toBool($data['sendingBlocked'] ?? null),
        );
    }
}
