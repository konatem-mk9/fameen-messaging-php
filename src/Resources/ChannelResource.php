<?php

declare(strict_types=1);

namespace Fameen\Messaging\Resources;

use Fameen\Messaging\Dto\MessageResource;
use Fameen\Messaging\FameenMessaging;
use Fameen\Messaging\Media;

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
     * @param array<string, mixed> $params  `['to' => …, 'message' => …, 'subject' => ?, 'statusCallback' => ?,
     *                                      'attachments' => ?, 'media' => ?, 'fileName' => ?]`
     *                                      (`subject` : canal email uniquement, ≤255 caractères ;
     *                                      médias : `content`/`media` = octets bruts, encodés par le SDK).
     * @param array<string, mixed> $options `['idempotencyKey' => string]` — rend les réessais sûrs (fenêtre 24 h).
     *
     * @throws \InvalidArgumentException Paramètres invalides (aucun appel réseau effectué).
     * @throws \Fameen\Messaging\Exceptions\ApiException Réponse d'erreur de l'API.
     * @throws \Fameen\Messaging\Exceptions\ConnectionException API injoignable après réessais.
     */
    public function send(array $params, array $options = []): MessageResource
    {
        $this->assertSendable($params, $this->channel());

        $body = Media::normalizeParams($params);
        $data = $this->client->request('POST', $this->path(), body: $body, idempotencyKey: $this->idempotencyKeyFrom($options));

        return MessageResource::fromArray(is_array($data) ? $data : []);
    }
}
