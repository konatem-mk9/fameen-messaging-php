<?php

/*
 * Configuration Laravel du SDK Fameen Messaging.
 *
 * Publiez ce fichier dans votre application :
 *   php artisan vendor:publish --tag=fameen-config
 * puis renseignez les variables dans votre .env.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Clé API
    |--------------------------------------------------------------------------
    | Clé du compte (format `fam_…`), créée dans le tableau de bord Fameen.
    | Ne la commitez jamais : gardez-la dans le .env.
    */
    'api_key' => env('FAMEEN_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Secret de webhook
    |--------------------------------------------------------------------------
    | Secret `whsec_…` servant à vérifier la signature `X-Fameen-Signature`
    | des callbacks de statut (voir Fameen\Messaging\Webhook::constructEvent).
    */
    'webhook_secret' => env('FAMEEN_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | URL de base de l'API
    |--------------------------------------------------------------------------
    */
    'base_url' => env('FAMEEN_BASE_URL', 'https://business.fameengroupe.com/api/v1'),

    /*
    |--------------------------------------------------------------------------
    | Timeout par tentative (millisecondes)
    |--------------------------------------------------------------------------
    */
    'timeout_ms' => (int) env('FAMEEN_TIMEOUT_MS', 30000),

    /*
    |--------------------------------------------------------------------------
    | Réessais automatiques
    |--------------------------------------------------------------------------
    | Nombre de réessais sur erreur réseau, 429 et 5xx (voir README pour la
    | sémantique exacte : un POST sans Idempotency-Key n'est jamais réessayé
    | sur 5xx).
    */
    'max_retries' => (int) env('FAMEEN_MAX_RETRIES', 2),

];
