<?php

namespace Goldnead\Teams\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An invitation into a team, addressed to an email.
 *
 * The token is stored only as its sha256 hash. Whoever reads the database
 * cannot use an open invitation; only whoever holds the mail can.
 *
 * @property int $id
 * @property string $uuid
 * @property int $team_id
 * @property string $email
 * @property string $role
 * @property array<string, mixed>|null $meta
 * @property string $token_hash
 * @property string|null $invited_by
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_by
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property-read Team|null $team
 */
class Invitation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'team_invitations';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'meta' => 'array',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Invitation $invitation) {
            if (empty($invitation->uuid)) {
                $invitation->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return static::query()->where('token_hash', static::hashToken($token))->first();
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => self::STATUS_ACCEPTED,
            $this->revoked_at !== null => self::STATUS_REVOKED,
            $this->expires_at !== null && $this->expires_at->isPast() => self::STATUS_EXPIRED,
            default => self::STATUS_PENDING,
        };
    }

    public function isPending(): bool
    {
        return $this->status() === self::STATUS_PENDING;
    }

    /**
     * Open invitations only: not accepted, not revoked, not expired.
     *
     * @param  Builder<Invitation>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * @return array{id: int, uuid: string, team_id: int, email: string, role: string, status: string, expires_at: string|null}
     */
    public function summary(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'uuid' => (string) $this->uuid,
            'team_id' => (int) $this->team_id,
            'email' => (string) $this->email,
            'role' => (string) $this->role,
            'status' => $this->status(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
