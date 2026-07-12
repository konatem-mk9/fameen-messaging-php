<?php

declare(strict_types=1);

namespace Fameen\Messaging\Resources;

/**
 * Validation locale des paramètres d'envoi, AVANT tout appel réseau
 * (meilleure DX : une erreur native immédiate plutôt qu'un aller-retour API).
 *
 * @internal
 */
trait ValidatesSendable
{
    /**
     * @param array<string, mixed> $params
     * @param string|null          $channel Canal demandé (`sms`|`whatsapp`|`email`), ou null si déduit côté serveur.
     *
     * @throws \InvalidArgumentException Si `to`/`message` manquent ou si le destinataire ne correspond pas au canal.
     */
    private function assertSendable(array $params, ?string $channel): void
    {
        $to = $params['to'] ?? null;
        if (!is_string($to) || trim($to) === '') {
            throw new \InvalidArgumentException('`to` est requis (numéro E.164 ou adresse email).');
        }

        $message = $params['message'] ?? null;
        if (!is_string($message) || trim($message) === '') {
            throw new \InvalidArgumentException('`message` est requis et ne peut pas être vide.');
        }

        if ($channel !== null && $channel !== 'email' && str_contains($to, '@')) {
            throw new \InvalidArgumentException(sprintf('`to` ressemble à un email mais le canal demandé est "%s".', $channel));
        }
    }

    /**
     * Extrait la clé d'idempotence des options par requête.
     *
     * @param array<string, mixed> $options `['idempotencyKey' => string]`
     */
    private function idempotencyKeyFrom(array $options): ?string
    {
        $key = $options['idempotencyKey'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }
}
