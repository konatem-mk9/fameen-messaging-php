<?php

declare(strict_types=1);

namespace Fameen\Messaging\Resources;

/**
 * Envoi d'email (`POST /v1/email/send`).
 *
 * ```php
 * $msg = $fameen->email()->send([
 *     'to' => 'client@exemple.com',
 *     'subject' => 'Votre facture',
 *     'message' => 'Bonjour {prenom}, votre facture est disponible.',
 * ]);
 * ```
 */
final class EmailResource extends ChannelResource
{
    protected function path(): string
    {
        return '/email/send';
    }

    protected function channel(): string
    {
        return 'email';
    }
}
