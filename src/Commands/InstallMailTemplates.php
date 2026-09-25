<?php

namespace Goldnead\Teams\Commands;

use Goldnead\Teams\Integrations\EmailTemplates\MailTemplates;
use Illuminate\Console\Command;

/**
 * `php please teams:mail-templates`
 *
 * Writes the shipped mail texts into goldnead/statamic-email-templates, so
 * an editor can change them in the CP. Existing templates are kept unless
 * `--overwrite`.
 */
class InstallMailTemplates extends Command
{
    protected $signature = 'teams:mail-templates {--overwrite : Replace templates that already exist}';

    protected $description = 'Write the Teams mails into the email templates collection.';

    public function handle(MailTemplates $templates): int
    {
        if (! $templates->available()) {
            $this->warn('goldnead/statamic-email-templates is not installed. The shipped texts are sent as they are.');

            return self::SUCCESS;
        }

        $result = $templates->install((bool) $this->option('overwrite'));

        foreach ($result['created'] as $slug) {
            $this->line("  written  {$slug}");
        }

        foreach ($result['skipped'] as $slug) {
            $this->line("  kept     {$slug} (exists, use --overwrite)");
        }

        $this->info(__('teams::messages.templates_installed', ['count' => count($result['created'])]));

        return self::SUCCESS;
    }
}
