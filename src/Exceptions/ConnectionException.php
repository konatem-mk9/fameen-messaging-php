<?php

declare(strict_types=1);

namespace Fameen\Messaging\Exceptions;

/**
 * Échec réseau : l'API Fameen n'a pas pu être jointe (DNS, timeout, coupure…).
 *
 * Jetée par le transport HTTP puis, après épuisement des réessais
 * automatiques, propagée au code appelant. La cause d'origine est disponible
 * via `getPrevious()`.
 */
class ConnectionException extends FameenException
{
}
