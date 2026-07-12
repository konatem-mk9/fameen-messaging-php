<?php

declare(strict_types=1);

namespace Fameen\Messaging\Resources;

use Fameen\Messaging\Dto\MessageResource;
use Fameen\Messaging\FameenMessaging;

/**
 * Socle commun des ressources par canal (`sms`, `whatsapp`, `email`).
 */
abstract class ChannelResource
{
    use ValidatesSendable;

    /** Chemin d'envoi du canal (ex. `/sms/send`). */
    abstract protected function path(): string;

    /** Nom du canal (`sms` | `whatsapp` | `email`). */
    abstract protected function channel(): string;

    public function __construct(protected readonly FameenMessaging $client)
    {
    }

    /**
     * Envoie un message sur ce canal (nécessite le scope correspondant de la clé API).
     *
     * @param array<string, mixed> $params  `['to' => …, 'message' => …, 'subject' => ?, 'statusCallback' => ?]`
     *                                      (`subject` : canal email uniquement, ≤255 caractères).
     * @param array<string, mixed> $options `['idempotencyKey' => string]` — rend les réessais sûrs (fenêtre 24 h).
     *
     * @throws \InvalidArgumentException Paramètres invalides (aucun appel réseau effectué).
     * @throws \Fameen\Messaging\Exceptions\ApiException Réponse d'erreur de l'API.
     * @throws \Fameen\Messaging\Exceptions\ConnectionException API injoignable après réessais.
     */
    public function send(array $params, array $options = []): MessageResource
    {
        $this->assertSendable($params, $this->channel());

        $data = $this->client->request('POST', $this->path(), body: $params, idempotencyKey: $this->idempotencyKeyFrom($options));

        return MessageResource::fromArray(is_array($data) ? $data : []);
    }
}
