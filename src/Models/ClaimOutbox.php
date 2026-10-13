<?php

namespace Goldnead\Teams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A durable, privacy-minimal record that a claim lifecycle event occurred.
 *
 * @property int $id
 * @property int $claim_id
 * @property string $kind
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon $available_at
 * @property Carbon|null $processed_at
 * @property int $attempts
 * @property string|null $last_error_code
 */
class ClaimOutbox extends Model
{
    public const REQUESTED = 'requested';

    public const DECIDED = 'decided';

    public $timestamps = false;

    protected $table = 'team_claim_outbox';

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
        'available_at' => 'datetime',
        'processed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(TeamClaim::class, 'claim_id');
    }
}
