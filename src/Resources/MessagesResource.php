<?php

declare(strict_types=1);

namespace Fameen\Messaging\Resources;

use Fameen\Messaging\Dto\MessageList;
use Fameen\Messaging\Dto\MessageResource;
use Fameen\Messaging\FameenMessaging;
use Fameen\Messaging\Media;

/**
 * Ressource « Messages » unifiée (façon Twilio) : envoi tous canaux,
 * consultation d'un message et liste paginée.
 */
final class MessagesResource
{
    use ValidatesSendable;

    public function __construct(private readonly FameenMessaging $client)
    {
    }

    /**
     * Envoie un message — canal explicite (`channel`) ou déduit du
     * destinataire (`@` dans `to` → email, sinon sms ; WhatsApp doit donc
     * toujours être explicite).
     *
     * @param array<string, mixed> $params  `['to' => …, 'message' => …, 'channel' => ?, 'subject' => ?, 'statusCallback' => ?]`
     * @param array<string, mixed> $options `['idempotencyKey' => string]`
     *
     * @throws \InvalidArgumentException Paramètres invalides (aucun appel réseau effectué).
     */
    public function create(array $params, array $options = []): MessageResource
    {
        $channel = $params['channel'] ?? null;
        $this->assertSendable($params, is_string($channel) ? $channel : null);

        $body = Media::normalizeParams($params);
        $data = $this->client->request('POST', '/messages', body: $body, idempotencyKey: $this->idempotencyKeyFrom($options));

        return MessageResource::fromArray(is_array($data) ? $data : []);
    }

    /**
     * Statut courant d'un message (`GET /v1/messages/{sid}`).
     *
     * @throws \InvalidArgumentException Si `$sid` est vide.
     */
    public function get(string $sid): MessageResource
    {
        if (trim($sid) === '') {
            throw new \InvalidArgumentException('`sid` est requis.');
        }

        $data = $this->client->request('GET', '/messages/' . rawurlencode(trim($sid)));

        return MessageResource::fromArray(is_array($data) ? $data : []);
    }

    /**
     * Liste paginée (`GET /v1/messages`), filtres facultatifs.
     *
     * @param array<string, mixed> $params `['channel' => ?, 'status' => ?, 'to' => ? (contient), 'page' => ?, 'limit' => ? (1–100, déf. 30)]`
     */
    public function list(array $params = []): MessageList
    {
        $data = $this->client->request('GET', '/messages', query: [
            'channel' => $params['channel'] ?? null,
            'status' => $params['status'] ?? null,
            'to' => $params['to'] ?? null,
            'page' => $params['page'] ?? null,
            'limit' => $params['limit'] ?? null,
        ]);

        return MessageList::fromArray(is_array($data) ? $data : []);
    }

    /**
     * Historique brut (`GET /v1/messages/history`) — lignes non garanties stables.
     *
     * @deprecated Endpoint historique conservé pour compatibilité — préférez {@see MessagesResource::list()}.
     *
     * @param array<string, mixed> $params `['channel' => ?, 'status' => ?, 'page' => ?]`
     *
     * @return array<string, mixed> `{ messages: array[], total, page, pages }`
     */
    public function history(array $params = []): array
    {
        $data = $this->client->request('GET', '/messages/history', query: [
            'channel' => $params['channel'] ?? null,
            'status' => $params['status'] ?? null,
            'page' => $params['page'] ?? null,
        ]);

        return is_array($data) ? $data : [];
    }
}
