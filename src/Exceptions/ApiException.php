<?php

declare(strict_types=1);

namespace Fameen\Messaging\Exceptions;

use Fameen\Messaging\Dto\RateLimitInfo;

/**
 * Erreur renvoyée par l'API (réponse HTTP non-2xx).
 *
 * `getErrorCode()` reprend `error.code` du corps de la réponse
 * (`unauthorized`, `insufficient_credits`, `channel_not_allowed`,
 * `rate_limited`, `not_found`, …). Si le corps est illisible, un code de
 * repli est déduit du statut HTTP :
 * 400→bad_request, 401→unauthorized, 402→insufficient_credits,
 * 403→channel_not_allowed, 404→not_found, 429→rate_limited,
 * ≥500→internal_error, sinon unknown_error.
 */
final class ApiException extends FameenException
{
    /**
     * @param string             $message    Message d'erreur humain (corps `error.message` si présent).
     * @param int                $status     Statut HTTP (401, 402, 403, 404, 429, 500…).
     * @param string             $errorCode  Code d'erreur stable de l'API (`error.code` ou repli par statut).
     * @param int|null           $retryAfter Secondes à attendre avant de réessayer (en-tête `Retry-After`), si fourni.
     * @param RateLimitInfo|null $rateLimit  Compteurs `X-RateLimit-*` connus au moment de l'erreur.
     */
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $errorCode,
        private readonly ?int $retryAfter = null,
        private readonly ?RateLimitInfo $rateLimit = null,
    ) {
        parent::__construct($message, $status);
    }

    /** Statut HTTP de la réponse en erreur (401, 402, 403, 404, 429, 500…). */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** Code d'erreur stable de l'API (`error.code` du corps, ou repli par statut). */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** Secondes à attendre avant de réessayer (en-tête `Retry-After` des 429), sinon null. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** Compteurs de limitation de débit (`X-RateLimit-*`) si connus, sinon null. */
    public function getRateLimit(): ?RateLimitInfo
    {
        return $this->rateLimit;
    }
}
