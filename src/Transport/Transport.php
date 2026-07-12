<?php

declare(strict_types=1);

namespace Fameen\Messaging\Transport;

use Fameen\Messaging\Exceptions\ConnectionException;

/**
 * Contrat du transport HTTP utilisé par le client.
 *
 * Le SDK n'a aucune dépendance runtime : l'implémentation par défaut est
 * {@see CurlTransport}. Injectez la vôtre (ou {@see MockTransport} en test)
 * via l'option `transport` du constructeur de
 * {@see \Fameen\Messaging\FameenMessaging}.
 */
interface Transport
{
    /**
     * Exécute une requête HTTP et renvoie la réponse brute (statut, en-têtes, corps).
     *
     * Le transport NE doit PAS jeter sur un statut HTTP d'erreur (4xx/5xx) :
     * la gestion des erreurs et des réessais appartient au client. Il ne
     * jette {@see ConnectionException} qu'en cas d'échec réseau (DNS,
     * timeout, connexion refusée…), c'est-à-dire quand aucune réponse HTTP
     * n'a pu être obtenue.
     *
     * @param string                $method    Méthode HTTP (`GET`, `POST`).
     * @param string                $url       URL absolue (query string incluse).
     * @param array<string, string> $headers   En-têtes de requête (nom => valeur).
     * @param string|null           $body      Corps JSON encodé, ou null si aucun corps.
     * @param int                   $timeoutMs Timeout de la tentative, en millisecondes.
     *
     * @throws ConnectionException Si l'API n'a pas pu être jointe.
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutMs): TransportResponse;
}
