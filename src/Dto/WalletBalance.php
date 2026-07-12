<?php

declare(strict_types=1);

namespace Fameen\Messaging\Dto;

/**
 * Soldes et mode de facturation (`GET /v1/wallet/balance`).
 */
final class WalletBalance
{
    use CastsFromArray;

    public function __construct(
        public readonly int|float $smsCredits,
        public readonly int|float $waCredits,
        public readonly int|float $emailCredits,
        public readonly BillingInfo $billing,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $billing = $data['billing'] ?? null;

        return new self(
            smsCredits: self::toNum($data['smsCredits'] ?? null),
            waCredits: self::toNum($data['waCredits'] ?? null),
            emailCredits: self::toNum($data['emailCredits'] ?? null),
            billing: BillingInfo::fromArray(is_array($billing) ? $billing : []),
        );
    }
}
