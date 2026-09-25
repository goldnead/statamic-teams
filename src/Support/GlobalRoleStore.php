<?php

namespace Goldnead\Teams\Support;

use Goldnead\Teams\Models\GlobalRole;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The stored global roles, read once per request or job.
 *
 * Bound `scoped`: Laravel drops it between requests and queue jobs, so a
 * long-running worker sees a change from the CP on its next job. A site
 * that has not run the migration yet works on the config alone.
 */
class GlobalRoleStore
{
    /** @var array<string, GlobalRole>|null */
    protected ?array $rows = null;

    /** @return array<string, GlobalRole> handle => row */
    public function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        try {
            if (! Schema::hasTable('team_global_roles')) {
                return $this->rows = [];
            }

            return $this->rows = GlobalRole::query()->orderBy('id')->get()->keyBy('handle')->all();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function flush(): void
    {
        $this->rows = null;
    }
}
