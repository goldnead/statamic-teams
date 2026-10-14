<?php

namespace Goldnead\Teams\Models;

use Illuminate\Database\Eloquent\Model;

/** @property string $claimant_id */
class ClaimLimitLock extends Model
{
    public $timestamps = false;

    protected $table = 'team_claim_limit_locks';

    protected $primaryKey = 'claimant_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];
}
