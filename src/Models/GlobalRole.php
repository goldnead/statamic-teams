<?php

namespace Goldnead\Teams\Models;

use Goldnead\Teams\Support\GlobalRoleStore;
use Illuminate\Database\Eloquent\Model;

/**
 * A global role as changed in the Control Panel, over `teams.roles`.
 *
 * @property int $id
 * @property string $handle
 * @property string $label
 * @property list<string>|null $permissions
 * @property bool $removed
 */
class GlobalRole extends Model
{
    protected $table = 'team_global_roles';

    protected $guarded = ['id'];

    protected $casts = [
        'permissions' => 'array',
        'removed' => 'boolean',
    ];

    /** The cached read forgets on every write through the model. */
    protected static function booted(): void
    {
        $flush = fn () => app(GlobalRoleStore::class)->flush();

        static::saved($flush);
        static::deleted($flush);
    }
}
