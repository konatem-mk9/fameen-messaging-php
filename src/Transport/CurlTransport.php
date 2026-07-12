<?php

declare(strict_types=1);

namespace Fameen\Messaging\Transport;

use Fameen\Messaging\Exceptions\ConnectionException;

/**
 * Transport HTTP par défaut, bâti sur l'extension cURL (aucune dépendance
 * Composer). Suit les redirections et remonte les en-têtes de la réponse
 * finale.
 */
final class CurlTransport implements Transport
{
    /**
     * {@inheritDoc}
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutMs): TransportResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new ConnectionException('Impossible d\'initialiser cURL.');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        /** @var array<string, string> $responseHeaders */
        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                $trimmed = trim($line);
                if ($trimmed !== '' && stripos($trimmed, 'HTTP/') === 0) {
                    // Nouvelle réponse (redirection) : on repart des en-têtes de ce hop.
                    $responseHeaders = [];

                    return strlen($line);
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $errno = curl_errno($handle);
            $error = curl_error($handle);
            curl_close($handle);

            throw new ConnectionException(sprintf('Échec réseau cURL (%d) : %s', $errno, $error !== '' ? $error : 'erreur inconnue'));
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return new TransportResponse($statusCode, $responseHeaders, (string) $raw);
    }
}
