<?php

declare(strict_types=1);

namespace Fameen\Messaging\Resources;

/**
 * Envoi WhatsApp (`POST /v1/whatsapp/send`).
 *
 * ```php
 * $msg = $fameen->whatsapp()->send(['to' => '+224620000000', 'message' => 'Votre commande est prête.']);
 * ```
 */
final class WhatsappResource extends ChannelResource
{
    protected function path(): string
    {
        return '/whatsapp/send';
    }

    protected function channel(): string
    {
        return 'whatsapp';
    }
}
