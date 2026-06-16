<?php

namespace App\Services\EventConsume;

use App\Services\EventConsume\Handlers\StoreCreatedHandler;
use App\Services\EventConsume\Handlers\StoreDeletedHandler;
use App\Services\EventConsume\Handlers\StoreUpdatedHandler;
use App\Services\EventConsume\Handlers\UserCreatedHandler;
use App\Services\EventConsume\Handlers\UserDeletedHandler;
use App\Services\EventConsume\Handlers\UserUpdatedHandler;
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
            "{$authPrefix}.user.created" => UserCreatedHandler::class,
            "{$authPrefix}.user.updated" => UserUpdatedHandler::class,
            "{$authPrefix}.user.deleted" => UserDeletedHandler::class,

            // STORES
            "{$authPrefix}.store.created" => StoreCreatedHandler::class,
            "{$authPrefix}.store.updated" => StoreUpdatedHandler::class,
            "{$authPrefix}.store.deleted" => StoreDeletedHandler::class,
        ];
    }

    public function resolve(string $subject): string
    {
        if (! isset($this->map[$subject])) {
            throw new Exception("No handler for subject '{$subject}'");
        }

        return $this->map[$subject];
    }
}
