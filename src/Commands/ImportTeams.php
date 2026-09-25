<?php

namespace Goldnead\Teams\Commands;

use Goldnead\Teams\Services\ImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * `php please teams:import teams.json`
 *
 * The file holds a list of teams in the shape `Teams::import()` takes (see
 * README, "Importing teams"). All teams go in one transaction: a file that
 * fails halfway leaves nothing behind, so it can be fixed and run again.
 */
class ImportTeams extends Command
{
    protected $signature = 'teams:import
        {file : Path to a JSON file with a list of teams}
        {--dry-run : Check the file and roll everything back}';

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

        try {
            DB::transaction(function () use ($teams, $importer, $dryRun) {
                foreach ($teams as $data) {
                    $team = $importer->import((array) $data);
                    $this->line(sprintf('  %s #%d %s (%d members)', $dryRun ? 'would import' : 'imported', $team->id, $team->name, $team->members()->count()));
                }

                if ($dryRun) {
                    throw new RuntimeException('dry-run');
                }
            });
        } catch (Throwable $e) {
            if ($dryRun && $e->getMessage() === 'dry-run') {
                $this->info('Dry run: '.count($teams).' teams checked, nothing written.');

                return self::SUCCESS;
            }

            $this->error('Import stopped, nothing was written: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(count($teams).' teams imported.');

        return self::SUCCESS;
    }
}
