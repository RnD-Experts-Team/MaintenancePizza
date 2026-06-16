<?php

namespace App\Services\EventConsume\Handlers;

use App\Models\User;
use App\Services\EventConsume\EventHandlerInterface;
use Illuminate\Support\Facades\DB;

class UserDeletedHandler implements EventHandlerInterface
{
    public function handle(array $event): void
    {
        $userId = $this->asInt(
            data_get($event, 'data.user_id')
                ?? data_get($event, 'user_id')
                ?? data_get($event, 'data.user.id')
                ?? data_get($event, 'user.id')
        );

        if ($userId <= 0) {
            throw new \Exception('UserDeletedHandler: missing/invalid user_id');
        }

        DB::transaction(function () use ($userId) {
            if (DB::getSchemaBuilder()->hasTable('user_groups')) {
                DB::table('user_groups')->where('user_id', $userId)->delete();
            }

            User::query()->where('id', $userId)->delete();
        });
    }

    private function asInt(mixed $v): int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && ctype_digit($v)) return (int) $v;
        if (is_numeric($v)) return (int) $v;
        return 0;
    }
}
