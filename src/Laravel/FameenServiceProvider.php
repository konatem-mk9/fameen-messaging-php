<?php

declare(strict_types=1);

namespace Fameen\Messaging\Laravel;

use Fameen\Messaging\FameenMessaging;

if (class_exists(\Illuminate\Support\ServiceProvider::class)) {
    /**
     * ServiceProvider Laravel : enregistre le client {@see FameenMessaging}
     * en singleton depuis `config('fameen.*')` et publie le fichier de
     * configuration.
     *
     * Auto-découvert par Laravel (`extra.laravel.providers` du composer.json).
     * Publiez la config puis renseignez `FAMEEN_API_KEY` dans votre `.env` :
     *
     * ```bash
     * php artisan vendor:publish --tag=fameen-config
     * ```
     */
    class FameenServiceProvider extends \Illuminate\Support\ServiceProvider
    {
        /**
         * Enregistre le singleton du client et l'alias conteneur `fameen`.
         */
        public function register(): void
        {
            $this->mergeConfigFrom(__DIR__ . '/../../config/fameen.php', 'fameen');

            $this->app->singleton(FameenMessaging::class, static function ($app): FameenMessaging {
                /** @var array<string, mixed> $config */
                $config = $app['config']->get('fameen', []);

                return new FameenMessaging(
                    apiKey: (string) ($config['api_key'] ?? ''),
                    baseUrl: isset($config['base_url']) && is_string($config['base_url']) && $config['base_url'] !== ''
                        ? $config['base_url']
                        : null,
                    timeoutMs: (int) ($config['timeout_ms'] ?? 30_000),
                    maxRetries: (int) ($config['max_retries'] ?? 2),
                );
            });

            $this->app->alias(FameenMessaging::class, 'fameen');
        }

        /**
         * Publie `config/fameen.php` (tag `fameen-config`).
         */
        public function boot(): void
        {
            if ($this->app->runningInConsole()) {
                $this->publishes([
                    __DIR__ . '/../../config/fameen.php' => $this->app->configPath('fameen.php'),
                ], 'fameen-config');
            }
        }
    }
} else {
    /**
     * Coquille inerte utilisée quand `illuminate/support` n'est pas installé :
     * évite toute erreur fatale si la classe est référencée hors Laravel.
     */
    class FameenServiceProvider
    {
        public function __construct(mixed ...$args)
        {
            throw new \RuntimeException(
                'Fameen\\Messaging\\Laravel\\FameenServiceProvider nécessite Laravel — installez illuminate/support.',
            );
        }
    }
}
