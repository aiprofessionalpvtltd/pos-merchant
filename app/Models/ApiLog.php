<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiLog extends Model
{
    use HasFactory;

    protected $table = 'api_logs';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'provider',
        'operation',
        'invoice_id',
        'our_reference',
        'provider_reference',
        'provider_status',
        'url',
        'payload',
        'status_code',
        'response_body',
        'duration_ms',
        'error',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'payload' => 'array',
        'response_body' => 'array',
    ];

    /**
     * A request body with every provider credential removed, safe to store.
     */
    public static function redact(array $payload): array
    {
        unset($payload['apiKey']);

        if (isset($payload['serviceParams'])) {
            unset($payload['serviceParams']['apiKey']);
        }

        return $payload;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scopeProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }
}
