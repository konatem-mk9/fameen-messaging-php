<?php

declare(strict_types=1);

namespace Fameen\Messaging;

/**
 * Pièces jointes des messages (WhatsApp & email).
 *
 * Le client transmet chaque fichier **encodé en base64** ; l'API l'héberge puis
 * le distribue (URL signée pour WhatsApp, pièce jointe inline pour l'email).
 * Aucune URL publique n'est requise de votre côté.
 *
 * Convention PHP : le champ `content` (et le raccourci `media`) contient les
 * **octets bruts** du fichier (ex. `file_get_contents('facture.pdf')`) — le SDK
 * les encode en base64 juste avant l'envoi. N'encodez donc pas vous-même.
 */
final class Media
{
    /** Encode des octets bruts en base64 pour le transport JSON. */
    public static function encode(string $raw): string
    {
        return base64_encode($raw);
    }

    /** `true` si les paramètres portent un média (raccourci `media` ou `attachments`). */
    public static function hasMedia(array $params): bool
    {
        if (isset($params['media']) && $params['media'] !== null && $params['media'] !== '') {
            return true;
        }

        return isset($params['attachments']) && is_array($params['attachments']) && count($params['attachments']) > 0;
    }

    /**
     * Construit une pièce jointe depuis un fichier local (contenu brut).
     *
     * ```php
     * $att = Fameen\Messaging\Media::fromFile('facture.pdf');
     * $fameen->email()->send(['to' => 'a@b.com', 'subject' => 'Facture', 'message' => '...', 'attachments' => [$att]]);
     * ```
     *
     * @param array{filename?: string, contentType?: string, type?: string} $opts
     *
     * @return array<string, mixed>
     */
    public static function fromFile(string $path, array $opts = []): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \InvalidArgumentException(sprintf('Pièce jointe illisible : "%s".', $path));
        }

        $att = [
            'content' => $raw,
            'filename' => $opts['filename'] ?? basename($path),
        ];
        $contentType = $opts['contentType'] ?? self::guessMime($path);
        if ($contentType !== null) {
            $att['contentType'] = $contentType;
        }
        if (!empty($opts['type'])) {
            $att['type'] = $opts['type'];
        }

        return $att;
    }

    /**
     * Encode en base64 le contenu média des paramètres (`media` + `attachments[].content`)
     * en laissant les autres champs intacts.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function normalizeParams(array $params): array
    {
        if (array_key_exists('media', $params) && is_string($params['media'])) {
            $params['media'] = self::encode($params['media']);
        }

        if (isset($params['attachments']) && is_array($params['attachments'])) {
            $params['attachments'] = array_map(static function ($att): array {
                if (!is_array($att) || !array_key_exists('content', $att)) {
                    throw new \InvalidArgumentException('Chaque pièce jointe doit fournir `content` (octets du fichier).');
                }
                if (is_string($att['content'])) {
                    $att['content'] = self::encode($att['content']);
                }

                return $att;
            }, array_values($params['attachments']));
        }

        return $params;
    }

    private static function guessMime(string $path): ?string
    {
        if (function_exists('mime_content_type') && is_file($path)) {
            $mime = @mime_content_type($path);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        $map = [
            'pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime', '3gp' => 'video/3gpp',
            'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'aac' => 'audio/aac',
            'amr' => 'audio/amr', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav',
            'doc' => 'application/msword', 'csv' => 'text/csv', 'txt' => 'text/plain',
            'zip' => 'application/zip',
        ];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $map[$ext] ?? null;
    }
}
