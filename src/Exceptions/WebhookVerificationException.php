<?php

declare(strict_types=1);

namespace Fameen\Messaging\Exceptions;

/**
 * Signature ou corps de webhook invalide — ne traitez PAS l'événement.
 *
 * Jetée par {@see \Fameen\Messaging\Webhook::constructEvent()} quand la
 * signature `X-Fameen-Signature` ne correspond pas au corps reçu, ou que le
 * corps n'est pas un JSON lisible. Répondez 401 à l'émetteur.
 */
class WebhookVerificationException extends FameenException
{
}
