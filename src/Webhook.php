<?php

declare(strict_types=1);

namespace Fameen\Messaging;

use Fameen\Messaging\Dto\WebhookEvent;
use Fameen\Messaging\Exceptions\WebhookVerificationException;

/**
 * Vérification des webhooks de statut Fameen.
 *
 * Signature = HMAC-SHA256 **hexadécimal** du **corps brut** de la requête
 * (octets reçus, AVANT tout parsing JSON), calculé avec le secret `whsec_…`
 * du compte. En-tête porteur : `X-Fameen-Signature` (info : `X-Fameen-Event`).
 */
final class Webhook
{
    private function __construct()
    {
    }

    /**
     * Vérifie la signature HMAC-SHA256 d'un webhook (comparaison en temps
     * constant via `hash_equals`). Signature absente → `false`.
     *
     * ⚠️ `$payload` doit être le **corps brut** de la requête
     * (`file_get_contents('php://input')` ou `$request->getContent()` sous
     * Laravel) : un re-`json_encode` ne produit pas forcément les mêmes octets.
     *
     * @param string      $payload   Corps brut reçu.
     * @param string|null $signature Valeur de l'en-tête `X-Fameen-Signature`.
     * @param string      $secret    Secret `whsec_…` du compte.
     *
     * @throws \InvalidArgumentException Si `$secret` est vide.
     */
    public static function verifySignature(string $payload, ?string $signature, string $secret): bool
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('`secret` est requis (secret "whsec_…" du compte).');
        }
        if ($signature === null || trim($signature) === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, trim($signature));
    }

    /**
     * Vérifie la signature PUIS parse l'événement — à appeler dans votre
     * handler de webhook. Jette {@see WebhookVerificationException} si la
     * signature ou le corps est invalide : répondez alors 401 et ne traitez
     * rien.
     *
     * ```php
     * $event = Webhook::constructEvent(
     *     file_get_contents('php://input'),
     *     $_SERVER['HTTP_X_FAMEEN_SIGNATURE'] ?? null,
     *     $secret,
     * );
     * // $event->sid / $event->status / $event->event
     * ```
     *
     * @throws \InvalidArgumentException       Si `$secret` est vide.
     * @throws WebhookVerificationException Signature invalide ou JSON illisible.
     */
    public static function constructEvent(string $payload, ?string $signature, string $secret): WebhookEvent
    {
        if (!self::verifySignature($payload, $signature, $secret)) {
            throw new WebhookVerificationException('Signature X-Fameen-Signature invalide — événement rejeté.');
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WebhookVerificationException('Corps de webhook illisible (JSON invalide).', 0, $e);
        }

        if (!is_array($decoded)) {
            throw new WebhookVerificationException('Corps de webhook illisible (JSON invalide).');
        }

        return WebhookEvent::fromArray($decoded);
    }
}
