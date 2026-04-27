<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailUnsubscribe extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'email',
        'reason',
        'person_id',
        'unsubscribed_at',
    ];

    protected $casts = [
        'unsubscribed_at' => 'datetime',
    ];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public static function isUnsubscribed(string $email): bool
    {
        return static::where('email', strtolower(trim($email)))->exists();
    }

    public static function record(string $email, string $reason = 'unsubscribe_link', ?string $personId = null): void
    {
        static::updateOrCreate(
            ['email' => strtolower(trim($email))],
            [
                'reason'          => $reason,
                'person_id'       => $personId,
                'unsubscribed_at' => now(),
            ]
        );
    }
}
