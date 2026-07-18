# Fameen Messaging — SDK PHP officiel

SDK PHP de l'API **Fameen Messaging** : envoi de **SMS**, **WhatsApp** et **Email** depuis votre application PHP ou Laravel.

- PHP ≥ 8.1, extensions `curl` et `json` — **zéro dépendance Composer** au runtime.
- Réessais automatiques sûrs (backoff exponentiel, `Retry-After` respecté, idempotence).
- Erreurs typées, DTO `readonly`, vérification de webhooks en temps constant.
- Pont **Laravel** intégré (ServiceProvider auto-découvert + facade `Fameen`).

## Installation

```bash
composer require fameen/messaging
```

## Démarrage rapide (PHP natif)

```php
<?php

use Fameen\Messaging\FameenMessaging;

require __DIR__ . '/vendor/autoload.php';

$fameen = new FameenMessaging(apiKey: getenv('FAMEEN_API_KEY')); // clé "fam_…", jamais en dur

// SMS
$msg = $fameen->sms()->send([
    'to' => '+224620000000',
    'message' => 'Bonjour {prenom}, votre commande est prête !',
]);
echo $msg->sid . ' → ' . $msg->status . PHP_EOL; // MSG… → queued

// WhatsApp
$fameen->whatsapp()->send(['to' => '+224620000000', 'message' => 'Votre code : 123456']);

// Email
$fameen->email()->send([
    'to' => 'client@exemple.com',
    'subject' => 'Votre facture',
    'message' => 'Bonjour {prenom}, votre facture est disponible.',
]);
```

### Ressource « Messages » unifiée

```php
// Canal explicite, ou déduit du destinataire (`@` dans `to` → email, sinon sms).
// WhatsApp doit donc toujours être explicite.
$msg = $fameen->messages()->create([
    'to' => '+224620000000',
    'message' => 'Bonjour !',
    'channel' => 'whatsapp',                            // 'sms' | 'whatsapp' | 'email'
    'statusCallback' => 'https://mon-app.com/webhooks/fameen', // URL HTTPS publique (optionnel)
]);

// Suivi d'un message
$msg = $fameen->messages()->get('MSGa1b2c3');
echo $msg->status; // queued | sending | sent | delivered | failed

// Liste paginée avec filtres
$page = $fameen->messages()->list([
    'channel' => 'sms',
    'status' => 'delivered',
    'to' => '+224',   // filtre « contient »
    'page' => 1,
    'limit' => 50,     // 1–100 (30 par défaut)
]);
foreach ($page->data as $m) {
    echo "{$m->sid} {$m->to} {$m->status}\n";
}
echo "{$page->total} messages, {$page->totalPages} pages";
```

### Solde du portefeuille

```php
$solde = $fameen->wallet()->balance();
echo $solde->smsCredits;             // crédits SMS restants
echo $solde->billing->mode;          // 'prepaid' | 'consumption'
echo $solde->billing->sendingBlocked // true = compte bloqué
    ? 'Envois bloqués' : 'OK';
```

### Idempotence (recommandé pour les envois)

Fournissez une clé d'idempotence : tout réessai dans les 24 h renvoie la
réponse d'origine au lieu de créer un doublon — et cela rend les réessais
automatiques du SDK **sûrs sur les POST** :

```php
$fameen->sms()->send(
    ['to' => '+224620000000', 'message' => 'Bonjour !'],
    ['idempotencyKey' => 'commande-4589-notif-1'],
);
```

## Médias (pièces jointes)

WhatsApp et email acceptent des pièces jointes (PDF, images, vidéo, audio). Passez les **octets bruts** du fichier dans `media`/`content` (ex. `file_get_contents(...)`) — le SDK les encode en base64 ; l'API héberge le fichier et le distribue. **SMS non supporté.** Quand un média est fourni, `message` peut être vide.

```php
use Fameen\Messaging\FameenMessaging;

// WhatsApp : un seul média par message, message = légende (facultative)
$fameen->whatsapp()->send([
    'to' => '+224620000000',
    'message' => 'Votre facture',
    'media' => file_get_contents('facture.pdf'),
    'fileName' => 'facture.pdf',
]);

// Email : plusieurs pièces jointes (fileAttachment lit le fichier et devine le type MIME)
$fameen->email()->send([
    'to' => 'client@exemple.com',
    'subject' => 'Vos documents',
    'message' => 'Bonjour, voir en pièces jointes.',
    'attachments' => [
        FameenMessaging::fileAttachment('facture.pdf'),
        FameenMessaging::fileAttachment('cgv.pdf'),
    ],
]);
```

Chaque pièce jointe : `['content' => <octets>, 'filename' => ..., 'contentType' => ..., 'type' => ...]` où `type` vaut `image | video | audio | document` (déduit du type MIME si absent). Max 16 Mo par fichier.

## Gestion des erreurs

Toutes les exceptions du SDK héritent de `Fameen\Messaging\Exceptions\FameenException`.

```php
use Fameen\Messaging\Exceptions\ApiException;
use Fameen\Messaging\Exceptions\ConnectionException;

try {
    $fameen->sms()->send(['to' => '+224620000000', 'message' => 'Bonjour !']);
} catch (ApiException $e) {
    // Réponse d'erreur de l'API (HTTP non-2xx)
    $e->getStatus();     // 402
    $e->getErrorCode();  // 'insufficient_credits'
    $e->getMessage();    // message humain
    $e->getRetryAfter(); // secondes à attendre (429), sinon null
    $e->getRateLimit();  // ?RateLimitInfo (limit / remaining / reset)
} catch (ConnectionException $e) {
    // API injoignable après épuisement des réessais (DNS, timeout…)
}
```

| Statut HTTP | `getErrorCode()` (repli) | Signification |
|---|---|---|
| 400 | `bad_request` | Paramètres invalides |
| 401 | `unauthorized` | Clé API absente/invalide/révoquée |
| 402 | `insufficient_credits` | Crédits insuffisants |
| 403 | `channel_not_allowed` | Canal non couvert par les scopes de la clé |
| 404 | `not_found` | Ressource introuvable |
| 429 | `rate_limited` | Limite de 60 requêtes/min/clé atteinte |
| ≥500 | `internal_error` | Erreur serveur |

> Le code réel provient de `error.code` du corps de la réponse ; le tableau
> ci-dessus est le repli utilisé quand le corps est illisible.

## Réessais automatiques

Jusqu'à `maxRetries` réessais (2 par défaut), backoff exponentiel
`retryBaseMs × 2^tentative + jitter` :

| Situation | Réessai ? |
|---|---|
| Erreur réseau (DNS, timeout, coupure) | ✅ Oui, toutes méthodes |
| HTTP 429 | ✅ Oui, en respectant l'en-tête `Retry-After` |
| HTTP 5xx sur GET | ✅ Oui |
| HTTP 5xx sur POST **avec** `idempotencyKey` | ✅ Oui (sans risque de doublon) |
| HTTP 5xx sur POST **sans** `idempotencyKey` | ❌ Jamais (l'envoi a pu être traité) |
| HTTP 4xx | ❌ Jamais |

### Limitation de débit

```php
$info = $fameen->lastRateLimit(); // ?RateLimitInfo, mis à jour à chaque réponse
if ($info !== null) {
    echo "{$info->remaining}/{$info->limit} requêtes restantes (reset à {$info->reset})";
}
```

## Webhooks de statut

Fameen signe chaque callback avec `HMAC-SHA256` (hexadécimal) du **corps
brut**, envoyé dans l'en-tête `X-Fameen-Signature` (type d'événement dans
`X-Fameen-Event`). Vérifiez **toujours** la signature avant de traiter :

```php
use Fameen\Messaging\Webhook;
use Fameen\Messaging\Exceptions\WebhookVerificationException;

$payload = file_get_contents('php://input');            // corps BRUT, avant tout json_decode
$signature = $_SERVER['HTTP_X_FAMEEN_SIGNATURE'] ?? null;

try {
    $event = Webhook::constructEvent($payload, $signature, getenv('FAMEEN_WEBHOOK_SECRET'));
} catch (WebhookVerificationException $e) {
    http_response_code(401); // signature invalide : ne rien traiter
    exit;
}

// $event est un Fameen\Messaging\Dto\WebhookEvent
match ($event->event) {
    'delivered' => marquerLivre($event->sid),
    'failed' => alerterEchec($event->sid, $event->error),
    default => null, // queued | sent
};

http_response_code(200);
```

Pour un simple booléen : `Webhook::verifySignature($payload, $signature, $secret)`
(comparaison en temps constant via `hash_equals`).

## Laravel

Le pont Laravel est **inclus dans ce paquet** (`Fameen\Messaging\Laravel`) et
auto-découvert — aucune installation supplémentaire.

### 1. Configuration

```bash
php artisan vendor:publish --tag=fameen-config
```

Puis dans votre `.env` :

```dotenv
FAMEEN_API_KEY=fam_votre_cle
FAMEEN_WEBHOOK_SECRET=whsec_votre_secret
# Optionnel :
# FAMEEN_BASE_URL=https://business.fameengroupe.com/api/v1
# FAMEEN_TIMEOUT_MS=30000
# FAMEEN_MAX_RETRIES=2
```

### 2. Facade ou injection

```php
use Fameen\Messaging\Laravel\Fameen; // alias auto-découvert : Fameen

Fameen::sms()->send(['to' => '+224620000000', 'message' => 'Bonjour !']);
$solde = Fameen::wallet()->balance();
```

Ou par injection du singleton :

```php
use Fameen\Messaging\FameenMessaging;

class NotificationService
{
    public function __construct(private readonly FameenMessaging $fameen)
    {
    }

    public function notifier(string $tel, string $texte): void
    {
        $this->fameen->sms()->send(['to' => $tel, 'message' => $texte]);
    }
}
```

### 3. Route webhook

```php
// routes/api.php
use Fameen\Messaging\Webhook;
use Fameen\Messaging\Exceptions\WebhookVerificationException;
use Illuminate\Http\Request;

Route::post('/webhooks/fameen', function (Request $request) {
    try {
        $event = Webhook::constructEvent(
            $request->getContent(),                  // corps BRUT
            $request->header('X-Fameen-Signature'),
            config('fameen.webhook_secret'),
        );
    } catch (WebhookVerificationException) {
        return response()->noContent(401);
    }

    // $event->sid / $event->status / $event->event …

    return response()->noContent(200);
});
```

> N'oubliez pas d'exclure cette route de la vérification CSRF si vous la
> placez dans `routes/web.php` (préférez `routes/api.php`).

## Options du constructeur

```php
use Fameen\Messaging\FameenMessaging;

$fameen = new FameenMessaging(
    apiKey: 'fam_…',        // requis
    baseUrl: 'https://business.fameengroupe.com/api/v1', // défaut
    timeoutMs: 30_000,       // timeout par tentative
    maxRetries: 2,           // réessais automatiques
    retryBaseMs: 500,        // base du backoff exponentiel
    transport: null,         // Transport custom (défaut : CurlTransport)
    sleeper: null,           // fn (int $ms): void — injectable en test
);
```

## Tester votre intégration sans réseau

Le paquet fournit `MockTransport`, le transport factice utilisé par ses
propres tests :

```php
use Fameen\Messaging\FameenMessaging;
use Fameen\Messaging\Transport\MockTransport;

$transport = new MockTransport();
$transport->queueJson(200, [
    'success' => true,
    'data' => ['sid' => 'MSGtest', 'status' => 'queued', 'channel' => 'sms', 'to' => '+224620000000'],
    'message' => 'OK',
]);

$fameen = new FameenMessaging(apiKey: 'fam_test', transport: $transport, retryBaseMs: 1);
$msg = $fameen->sms()->send(['to' => '+224620000000', 'message' => 'Bonjour !']);

$transport->lastRequest(); // ['method' => 'POST', 'url' => …, 'headers' => …, 'body' => …]
```

### Lancer les tests du SDK

```bash
composer install
vendor/bin/phpunit          # Windows : vendor\bin\phpunit.bat
```

## Licence

MIT — © Fameen Groupe.
