<?php

namespace Goldnead\Teams\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Statamic\Contracts\Auth\User;
use Statamic\Facades\User as UserRepository;

/**
 * A private moderation record for a team to claim a host resource.
 *
 * @property int $id
 * @property string $resource_type
 * @property string $resource_key
 * @property string $site
 * @property string $claimant_id
 * @property string $justification
 * @property string $contact
 * @property string $status
 * @property int|null $team_id
 * @property string|null $decision_by
 * @property string|null $decision_note
 * @property Carbon|null $decided_at
 * @property string|null $pending_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $team
 */
class TeamClaim extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'team_claims';

    protected $guarded = ['id'];

    protected $hidden = ['claimant_id', 'justification', 'contact', 'decision_by', 'decision_note', 'pending_key'];

    protected $casts = ['decided_at' => 'datetime'];

    public function claimantUser(): ?User
    {
        return UserRepository::find($this->claimant_id);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
