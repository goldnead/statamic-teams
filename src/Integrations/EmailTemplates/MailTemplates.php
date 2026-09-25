<?php

namespace Goldnead\Teams\Integrations\EmailTemplates;

use Throwable;

/**
 * Every mail of this addon, as a template in goldnead/statamic-email-templates.
 *
 * The text shipped with the addon (`lang/<locale>/mail.php`) is the default
 * template. With email-templates installed, `php please teams:mail-templates`
 * (or `email-templates:import --source=Teams`) writes it into the
 * `et_templates` collection, where an editor changes it in the CP. From then
 * on the CP entry wins. Without email-templates, or before the import, the
 * shipped text is sent: a missing template never means a missing mail.
 */
class MailTemplates
{
    public const FACADE = 'Goldnead\EmailTemplates\Facades\EmailTemplates';

    public const MERGE = 'Goldnead\EmailTemplates\Support\MergeVariables';

    public const COLLECTION_MANAGER = 'Goldnead\EmailTemplates\Services\EmailTemplateCollectionManager';

    public const DATA = 'Goldnead\EmailTemplates\Support\EmailTemplateData';

    /** Variables that carry a URL and must not be HTML-escaped (`&` in a query). */
    public const RAW = ['accept_url', 'team_url'];

    /**
     * The mails, keyed as under `teams.mail`, with the variables each one
     * understands. The variable list is shown in the CP and the README.
     *
     * @var array<string, list<string>>
     */
    public const MAILS = [
        'invitation' => ['team.name', 'inviter.name', 'role', 'email', 'accept_url', 'expires_at'],
        'member_joined' => ['team.name', 'member.name', 'member.email', 'role'],
        'member_removed' => ['team.name', 'member.name'],
        'role_changed' => ['team.name', 'member.name', 'role.from', 'role.to'],
    ];

    public function available(): bool
    {
        return (bool) config('teams.integrations.email_templates', true) && class_exists(self::FACADE);
    }

    public function enabled(string $mail): bool
    {
        return (bool) config("teams.mail.{$mail}.enabled", false);
    }

    public function slug(string $mail): string
    {
        return (string) config("teams.mail.{$mail}.template", 'teams-'.str_replace('_', '-', $mail));
    }

    /**
     * The shipped default of one mail, in the current locale.
     *
     * @return array{title: string, subject: string, body: string}
     */
    public function default(string $mail): array
    {
        return [
            'title' => (string) __("teams::mail.{$mail}.title"),
            'subject' => (string) __("teams::mail.{$mail}.subject"),
            'body' => (string) __("teams::mail.{$mail}.body"),
        ];
    }

    /**
     * Subject and HTML body, ready to send.
     *
     * @param  array<string, mixed>  $data
     * @return array{subject: string, html: string, source: string}
     */
    public function render(string $mail, array $data): array
    {
        $default = $this->default($mail);

        if ($this->available()) {
            try {
                $facade = self::FACADE;
                $resolved = $facade::resolve($this->slug($mail), fn () => $default + ['source' => 'teams-default']);

                if ($resolved !== null) {
                    return [
                        'subject' => $this->merge((string) $resolved->subject, $data, false),
                        'html' => $this->merge((string) $resolved->body, $data, true),
                        'source' => (string) $resolved->source,
                    ];
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return [
            'subject' => $this->merge($default['subject'], $data, false),
            'html' => view('teams::mail.layout', [
                'body' => $this->merge($default['body'], $data, true),
                'subject' => $this->merge($default['subject'], $data, false),
            ])->render(),
            'source' => 'teams-default',
        ];
    }

    /**
     * Whether the mail has its own entry in `et_templates` (edited in the
     * CP), for the Wiring page.
     */
    public function hasEntry(string $mail): bool
    {
        if (! $this->available() || ! class_exists(self::COLLECTION_MANAGER)) {
            return false;
        }

        try {
            return app(self::COLLECTION_MANAGER)->findBySlug($this->slug($mail)) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function editUrl(string $mail): ?string
    {
        if (! $this->hasEntry($mail)) {
            return null;
        }

        try {
            $entry = app(self::COLLECTION_MANAGER)->findBySlug($this->slug($mail));

            return $entry?->editUrl();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Write the shipped defaults into `et_templates`. Existing entries are
     * left alone unless `$overwrite`: an editor's wording is not replaced
     * by an update of this addon.
     *
     * @return array{created: list<string>, skipped: list<string>}
     */
    public function install(bool $overwrite = false): array
    {
        $result = ['created' => [], 'skipped' => []];

        if (! $this->available() || ! class_exists(self::COLLECTION_MANAGER)) {
            return $result;
        }

        $manager = app(self::COLLECTION_MANAGER);
        $manager->ensure();

        foreach ($this->templates() as $template) {
            if (! $overwrite && $manager->findBySlug($template->slug) !== null) {
                $result['skipped'][] = $template->slug;

                continue;
            }

            $manager->upsert($template);
            $result['created'][] = $template->slug;
        }

        return $result;
    }

    /**
     * The defaults as email-templates' own data objects.
     *
     * @return list<object>
     */
    public function templates(): array
    {
        if (! class_exists(self::DATA)) {
            return [];
        }

        $data = self::DATA;
        $templates = [];

        foreach (array_keys(self::MAILS) as $mail) {
            $default = $this->default($mail);

            $templates[] = $data::fromArray([
                'slug' => $this->slug($mail),
                'title' => $default['title'],
                'subject' => $default['subject'],
                'body' => $default['body'],
                'description' => (string) __('teams::mail.description', ['variables' => implode(', ', array_map(fn ($v) => '{{ '.$v.' }}', self::MAILS[$mail]))]),
            ]);
        }

        return $templates;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function merge(string $text, array $data, bool $escape): string
    {
        if (class_exists(self::MERGE)) {
            $merge = self::MERGE;

            return (string) $merge::apply($text, $data, $escape, self::RAW);
        }

        $flat = $this->flatten($data);

        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $m) use ($flat, $escape) {
            if (! array_key_exists($m[1], $flat)) {
                return $m[0];
            }

            $value = (string) $flat[$m[1]];

            return $escape && ! in_array($m[1], self::RAW, true) ? e($value) : $value;
        }, $text);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, scalar|null>
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);
            } elseif (is_scalar($value) || $value === null) {
                $flat[$path] = $value;
            }
        }

        return $flat;
    }
}
