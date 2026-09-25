<?php

namespace Goldnead\Teams\Support;

use Goldnead\Teams\Models\GlobalRole;
use Illuminate\Database\QueryException;

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
            return $this->rows = GlobalRole::query()->orderBy('id')->get()->keyBy('handle')->all();
        } catch (QueryException $e) {
            // Only a missing table (migration not run yet) means "config
            // only". Anything else must not be read as "no changes": a role
            // deleted or narrowed in the CP would get its config permissions
            // back for as long as the database misbehaves.
            if (! self::isMissingTable($e)) {
                throw $e;
            }

            return $this->rows = [];
        }
    }

    public static function isMissingTable(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $message = $e->getMessage();

        return $state === '42S02'                              // MySQL, SQL Server
            || $state === '42P01'                              // PostgreSQL
            || str_contains($message, 'no such table');       // SQLite (HY000)
    }

    public function flush(): void
    {
        $this->rows = null;
    }
}
