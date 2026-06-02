<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'id', 'image_path'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Tickets created by this user.
     *
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'created_by');
    }

    protected $appends = [
        'image_url',
    ];


    public function getImageUrlAttribute(): ?string
    {
        $path = $this->image_path;

        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, 'storage/') || str_starts_with($path, '/storage/')) {
            return $this->authUrl($path);
        }

        $baseUrl = $this->authBaseUrl();

        if ($baseUrl === '') {
            return url($path);
        }

        return $baseUrl . '/storage/' . ltrim($path, '/');
    }

    protected function authUrl(string $path): string
    {
        $baseUrl = $this->authBaseUrl();

        if ($baseUrl === '') {
            return url($path);
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }

    protected function authBaseUrl(): string
    {
        $baseUrl = rtrim((string) config('services.auth_server.base_url'), '/');

        if ($baseUrl === '') {
            return '';
        }

        $baseUrl = preg_replace('~/api(?:/.*)?$~i', '', $baseUrl);

        return rtrim((string) $baseUrl, '/');
    }
}
