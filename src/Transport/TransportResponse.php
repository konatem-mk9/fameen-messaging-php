<?php

declare(strict_types=1);

namespace Fameen\Messaging\Transport;

/**
 * Réponse HTTP brute renvoyée par un {@see Transport}.
 *
 * Les noms d'en-têtes sont normalisés en minuscules ; utilisez
 * {@see TransportResponse::header()} pour une lecture insensible à la casse.
 */
final class TransportResponse
{
    /** @var array<string, string> En-têtes de réponse, clés en minuscules. */
    public readonly array $headers;

    /**
     * @param int                   $statusCode Statut HTTP (200, 402, 429…).
     * @param array<string, string> $headers    En-têtes de réponse (casse libre, normalisée ici).
     * @param string                $body       Corps brut de la réponse.
     */
    public function __construct(
        public readonly int $statusCode,
        array $headers = [],
        public readonly string $body = '',
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = (string) $value;
        }
        $this->headers = $normalized;
    }

    /** Valeur d'un en-tête (insensible à la casse), ou null s'il est absent. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
