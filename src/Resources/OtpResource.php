<?php

declare(strict_types=1);

namespace Fameen\Messaging\Resources;

use Fameen\Messaging\Dto\VerificationResource;
use Fameen\Messaging\FameenMessaging;
use InvalidArgumentException;

/**
 * Codes à usage unique (« Verify ») par SMS, WhatsApp ou email.
 *
 * Le code est généré, stocké haché et vérifié **côté serveur** : il ne transite
 * jamais par votre application et n'apparaît dans aucune réponse.
 *
 * ```php
 * $v = $fameen->otp()->send('+224620000000', channel: 'sms');
 * $r = $fameen->otp()->verify('483920', verificationId: $v->verificationId);
 * if ($r->isApproved()) { /* authentifie *\/ }
 * ```
 */
final class OtpResource
{
    public function __construct(private readonly FameenMessaging $client)
    {
    }

    /**
     * Génère un code et l'envoie sur le canal choisi.
     *
     * Nécessite le scope du canal utilisé et consomme un crédit de ce canal,
     * exactement comme un message ordinaire.
     *
     * @param string      $to             Numéro international, ou adresse email pour le canal email.
     * @param string|null $channel        `sms` | `whatsapp` | `email`. Déduit de `$to` si null.
     * @param int|null    $codeLength     4 à 8 chiffres.
     * @param int|null    $ttlSeconds     60 à 3600 secondes.
     * @param int|null    $maxAttempts    1 à 10 tentatives.
     * @param string|null $template       Doit contenir le marqueur `{{code}}`.
     * @param string|null $subject        Objet du message (canal email).
     * @param string|null $statusCallback URL HTTPS de suivi du message porteur.
     */
    public function send(
        string $to,
        ?string $channel = null,
        ?int $codeLength = null,
        ?int $ttlSeconds = null,
        ?int $maxAttempts = null,
        ?string $template = null,
        ?string $subject = null,
        ?string $statusCallback = null,
        ?string $idempotencyKey = null,
    ): VerificationResource {
        $destination = trim($to);
        if ($destination === '') {
            throw new InvalidArgumentException('`to` est requis.');
        }
        if ($channel !== null && $channel !== 'email' && str_contains($destination, '@')) {
            throw new InvalidArgumentException(
                sprintf('`to` ressemble à un email mais le canal demandé est "%s".', $channel)
            );
        }
        if ($template !== null && !str_contains($template, '{{code}}')) {
            throw new InvalidArgumentException('`template` doit contenir le marqueur {{code}}.');
        }

        $body = array_filter(
            [
                'to' => $destination,
                'channel' => $channel,
                'codeLength' => $codeLength,
                'ttlSeconds' => $ttlSeconds,
                'maxAttempts' => $maxAttempts,
                'template' => $template,
                'subject' => $subject,
                'statusCallback' => $statusCallback,
            ],
            static fn (mixed $v): bool => $v !== null,
        );

        $data = $this->client->request('POST', '/otp/send', [], $body, $idempotencyKey);

        return VerificationResource::fromArray(is_array($data) ? $data : []);
    }

    /**
     * Contrôle le code saisi par l'utilisateur.
     *
     * Ne lève **pas** d'exception sur un code erroné : la réponse porte
     * `status = 'rejected'` et `reason`. Testez `isApproved()`.
     *
     * Identifiez la vérification par `$verificationId` (recommandé) ou, à
     * défaut, par `$to` — la vérification en cours la plus récente est utilisée.
     */
    public function verify(
        string $code,
        ?string $verificationId = null,
        ?string $to = null,
        ?string $channel = null,
    ): VerificationResource {
        $value = trim($code);
        if ($value === '') {
            throw new InvalidArgumentException('`code` est requis.');
        }
        $ver = $verificationId !== null ? trim($verificationId) : '';
        $dest = $to !== null ? trim($to) : '';
        if ($ver === '' && $dest === '') {
            throw new InvalidArgumentException('Fournissez `verificationId` ou `to`.');
        }

        $body = array_filter(
            [
                'code' => $value,
                'verificationId' => $ver !== '' ? $ver : null,
                'to' => $dest !== '' ? $dest : null,
                'channel' => $channel,
            ],
            static fn (mixed $v): bool => $v !== null,
        );

        $data = $this->client->request('POST', '/otp/verify', [], $body);

        return VerificationResource::fromArray(is_array($data) ? $data : []);
    }

    /** État courant d'une vérification (jamais le code). */
    public function get(string $verificationId): VerificationResource
    {
        $value = trim($verificationId);
        if ($value === '') {
            throw new InvalidArgumentException('`verificationId` est requis.');
        }

        $data = $this->client->request('GET', '/otp/' . rawurlencode($value));

        return VerificationResource::fromArray(is_array($data) ? $data : []);
    }
}
