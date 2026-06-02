<?php

namespace App\Services\EventConsume;

use Exception;

class EventRouter
{
    private array $map;
    public function __construct()
    {
        $devMode = (bool) config('nats.dev_mode');

        $authPrefix = $devMode
            ? 'auth.testing.v1'
            : 'auth.v1';


        $this->map = [
            // USERS
            "{$authPrefix}.user.created" => \App\Services\EventConsume\Handlers\UserCreatedHandler::class,
            "{$authPrefix}.user.updated" => \App\Services\EventConsume\Handlers\UserUpdatedHandler::class,
            "{$authPrefix}.user.deleted" => \App\Services\EventConsume\Handlers\UserDeletedHandler::class,

            // STORES
            "{$authPrefix}.store.created" => \App\Services\EventConsume\Handlers\StoreCreatedHandler::class,
            "{$authPrefix}.store.updated" => \App\Services\EventConsume\Handlers\StoreUpdatedHandler::class,
            "{$authPrefix}.store.deleted" => \App\Services\EventConsume\Handlers\StoreDeletedHandler::class,
        ];
    }

    public function resolve(string $subject): string
    {
        if (!isset($this->map[$subject])) {
            throw new Exception("No handler for subject '{$subject}'");
        }

        return $this->map[$subject];
    }
}
