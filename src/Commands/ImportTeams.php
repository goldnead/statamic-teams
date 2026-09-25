<?php

namespace Goldnead\Teams\Commands;

use Goldnead\Teams\Services\ImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * `php please teams:import teams.json [--dry-run]`
 *
 * The file holds a list of teams in the shape `Teams::import()` takes (see
 * README, "Importing teams"). All teams go in one transaction: a file that
 * fails halfway leaves nothing behind, so it can be fixed and run again.
 *
 * `--dry-run` goes through every team, each in its own savepoint, and lists
 * every problem (an id another team holds, an unknown role) instead of
 * stopping at the first; then it rolls everything back. Warnings (what was
 * taken over only approximately) are listed in both modes.
 */
class ImportTeams extends Command
{
    protected $signature = 'teams:import
        {file : Path to a JSON file with a list of teams}
        {--dry-run : Check the whole file, list every problem, write nothing}';

    protected $description = 'Import teams with their members, roles, join codes and invitations, keeping ids and uuids.';

    public function handle(ImportService $importer): int
    {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error("No file at [{$path}].");

            return self::FAILURE;
        }

        $teams = json_decode((string) file_get_contents($path), true);

        if (! is_array($teams) || ! array_is_list($teams)) {
            $this->error('The file must hold a JSON list of teams.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $problems = [];
        $importer->flushWarnings();

        try {
            DB::transaction(function () use ($teams, $importer, $dryRun, &$problems) {
                foreach ($teams as $index => $data) {
                    $label = (string) ($data['name'] ?? '#'.$index);

                    try {
                        // A savepoint per team in a dry run, so one bad team
                        // does not hide the problems of the next.
                        $team = $dryRun
                            ? DB::transaction(fn () => $importer->import((array) $data))
                            : $importer->import((array) $data);

                        $this->line(sprintf('  %s #%d %s (%d members)', $dryRun ? 'ok' : 'imported', $team->id, $team->name, $team->members()->count()));
                    } catch (Throwable $e) {
                        if (! $dryRun) {
                            throw $e;
                        }

                        $problems[] = "{$label}: {$e->getMessage()}";
                        $this->line("  <error>problem</error> {$label}: {$e->getMessage()}");
                    }
                }

                if ($dryRun) {
                    throw new RuntimeException('dry-run');
                }
            });
        } catch (Throwable $e) {
            if (! $dryRun || $e->getMessage() !== 'dry-run') {
                $this->printWarnings($importer);
                $this->error('Import stopped, nothing was written: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $this->printWarnings($importer);

        if ($dryRun) {
            if ($problems !== []) {
                $this->error(count($problems).' of '.count($teams).' teams cannot be imported. Nothing was written.');

                return self::FAILURE;
            }

            $this->info('Dry run: '.count($teams).' teams checked, nothing written.');

            return self::SUCCESS;
        }

        $this->info(count($teams).' teams imported.');

        return self::SUCCESS;
    }

    protected function printWarnings(ImportService $importer): void
    {
        foreach ($importer->warnings() as $warning) {
            $this->warn('  warning '.$warning);
        }
    }
}
