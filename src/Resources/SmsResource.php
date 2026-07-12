<?php

declare(strict_types=1);

namespace Fameen\Messaging\Resources;

/**
 * Envoi de SMS (`POST /v1/sms/send`).
 *
 * ```php
 * $msg = $fameen->sms()->send(['to' => '+224620000000', 'message' => 'Bonjour {prenom} !']);
 * ```
 */
final class SmsResource extends ChannelResource
{
    protected function path(): string
    {
        return '/sms/send';
    }

    protected function channel(): string
    {
        return 'sms';
    }
}
