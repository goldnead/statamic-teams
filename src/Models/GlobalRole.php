<?php

namespace Goldnead\Teams\Models;

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
}
