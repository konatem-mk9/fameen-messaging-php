<?php

declare(strict_types=1);

namespace Fameen\Messaging\Resources;

use Fameen\Messaging\Dto\WalletBalance;
use Fameen\Messaging\FameenMessaging;

/**
 * Portefeuille du compte (`GET /v1/wallet/balance`).
 */
final class WalletResource
{
    public function __construct(private readonly FameenMessaging $client)
    {
    }

    /**
     * Soldes SMS / WhatsApp / Email et mode de facturation.
     */
    public function balance(): WalletBalance
    {
        $data = $this->client->request('GET', '/wallet/balance');

        return WalletBalance::fromArray(is_array($data) ? $data : []);
    }
}
