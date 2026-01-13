<?php

namespace CPP\ZNSPackage\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class ZaloOAToken extends Model
{
    protected $table = 'zalo_oa_tokens';

    protected $fillable = [
        'oa_id',
        'app_id',
        'access_token',
        'refresh_token',
        'expires_in',
        'expires_at',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
    ];

    /**
     * Check if the access token is expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Get token by OA ID
     */
    public static function findByOAId(string $oaId): ?self
    {
        return static::where('oa_id', $oaId)->first();
    }

    /**
     * Store or update token
     */
    public static function storeToken(string $oaId, array $tokenData): self
    {
        return static::updateOrCreate(
            ['oa_id' => $oaId],
            [
                'app_id' => $tokenData['app_id'] ?? config('zns.app_id'),
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'],
                'expires_in' => $tokenData['expires_in'] ?? 90000,
                'expires_at' => isset($tokenData['expires_at'])
                    ? Carbon::createFromTimestamp($tokenData['expires_at'])
                    : now()->addSeconds($tokenData['expires_in'] ?? 90000),
            ]
        );
    }
}