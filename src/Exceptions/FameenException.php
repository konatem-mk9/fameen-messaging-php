<?php

declare(strict_types=1);

namespace Fameen\Messaging\Exceptions;

/**
 * Classe mère de toutes les exceptions du SDK Fameen Messaging.
 *
 * Attrapez-la pour intercepter d'un bloc toute erreur émise par le SDK
 * (API, réseau, webhook) :
 *
 * ```php
 * try {
 *     $fameen->sms()->send(['to' => '+224620000000', 'message' => 'Bonjour !']);
 * } catch (\Fameen\Messaging\Exceptions\FameenException $e) {
 *     // journaliser / réessayer plus tard…
 * }
 * ```
 */
class FameenException extends \Exception
{
}
