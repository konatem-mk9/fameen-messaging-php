<?php

declare(strict_types=1);

namespace Fameen\Messaging\Laravel;

use Fameen\Messaging\FameenMessaging;

if (class_exists(\Illuminate\Support\Facades\Facade::class)) {
    /**
     * Facade Laravel du client Fameen Messaging (alias auto-découvert `Fameen`).
     *
     * ```php
     * use Fameen\Messaging\Laravel\Fameen;
     *
     * Fameen::sms()->send(['to' => '+224620000000', 'message' => 'Bonjour !']);
     * $solde = Fameen::wallet()->balance();
     * ```
     *
     * @method static \Fameen\Messaging\Resources\SmsResource sms()
     * @method static \Fameen\Messaging\Resources\WhatsappResource whatsapp()
     * @method static \Fameen\Messaging\Resources\EmailResource email()
     * @method static \Fameen\Messaging\Resources\MessagesResource messages()
     * @method static \Fameen\Messaging\Resources\WalletResource wallet()
     * @method static \Fameen\Messaging\Dto\RateLimitInfo|null lastRateLimit()
     *
     * @see FameenMessaging
     */
    class Fameen extends \Illuminate\Support\Facades\Facade
    {
        /**
         * Accesseur du conteneur : le singleton {@see FameenMessaging}.
         */
        protected static function getFacadeAccessor(): string
        {
            return FameenMessaging::class;
        }
    }
} else {
    /**
     * Coquille inerte utilisée quand `illuminate/support` n'est pas installé.
     */
    class Fameen
    {
        /**
         * @param array<int, mixed> $arguments
         */
        public static function __callStatic(string $method, array $arguments): mixed
        {
            throw new \RuntimeException(
                'La facade Fameen nécessite Laravel — installez illuminate/support, '
                . 'ou instanciez directement Fameen\\Messaging\\FameenMessaging.',
            );
        }
    }
}
