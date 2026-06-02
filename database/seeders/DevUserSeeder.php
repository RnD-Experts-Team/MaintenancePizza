<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DevUserSeeder extends Seeder
{
    /**
     * Create a development user and print a Sanctum token for testing.
     * (No registration/login endpoints exist — auth is handled elsewhere.)
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'dev@maintenancepizza.test'],
            ['name' => 'Dev User', 'password' => Hash::make('password')],
        );

        $token = $user->createToken('dev')->plainTextToken;

        $this->command->newLine();
        $this->command->warn('=================================================================');
        $this->command->warn(' DEV API TOKEN (send as header: Authorization: Bearer <token>)');
        $this->command->warn(' '.$token);
        $this->command->warn('=================================================================');
        $this->command->newLine();
    }
}
