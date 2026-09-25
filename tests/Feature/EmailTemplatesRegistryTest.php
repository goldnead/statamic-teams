<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\EmailTemplates\EmailTemplatesServiceProvider;
use Goldnead\EmailTemplates\Registry\TemplateRegistry;
use Goldnead\Teams\Events\InvitationSent;
use Goldnead\Teams\Events\MemberJoined;
use Goldnead\Teams\Integrations\EmailTemplates\TeamsTemplateSource;
use Goldnead\Teams\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Against the real registry of goldnead/statamic-email-templates (dev
 * dependency). The addon itself stays optionally coupled: it registers only
 * when `email-templates.registry` is bound.
 */
class EmailTemplatesRegistryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            EmailTemplatesServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(TemplateRegistry::class)) {
            $this->markTestSkipped('statamic-email-templates without registry.');
        }
    }

    #[Test]
    public function the_four_mails_are_registered_with_occasion_event_and_placeholders(): void
    {
        $registry = app('email-templates.registry');

        foreach (['teams-invitation', 'teams-member-joined', 'teams-member-removed', 'teams-role-changed'] as $slug) {
            $this->assertTrue($registry->has($slug), $slug);
            $this->assertSame('Teams', $registry->find($slug)->addon());
        }

        $invitation = $registry->find('teams-invitation');
        $this->assertSame(InvitationSent::class, $invitation->event);
        $this->assertSame(MemberJoined::class, $registry->find('teams-member-joined')->event);
        $this->assertSame(__('teams::mail.invitation.trigger'), $invitation->trigger());
        $this->assertNotSame('', $invitation->trigger());

        $placeholders = $invitation->placeholders();
        $this->assertSame(['team.name', 'inviter.name', 'role', 'email', 'accept_url', 'expires_at'], array_keys($placeholders));
        $this->assertNotSame('', $placeholders['accept_url']['label']);
        $this->assertNotNull($placeholders['accept_url']['example']);
        $this->assertSame($placeholders['team.name']['example'], data_get($invitation->examples(), 'team.name'));

        $defaults = $invitation->defaults();
        $this->assertSame(__('teams::mail.invitation.subject'), $defaults['subject']);
        $this->assertStringContainsString('{{ accept_url }}', $defaults['body']);
    }

    #[Test]
    public function the_occasion_follows_the_locale_that_reads_it(): void
    {
        $invitation = app('email-templates.registry')->find('teams-invitation');

        app()->setLocale('de');
        $german = $invitation->trigger();
        app()->setLocale('en');

        $this->assertNotSame($german, $invitation->trigger());
    }

    #[Test]
    public function the_registry_describes_it_as_sent_by_teams(): void
    {
        $this->assertStringContainsString('Teams', (string) app('email-templates.registry')->describe('teams-invitation'));
    }

    #[Test]
    public function the_old_import_source_is_not_tagged_twice(): void
    {
        $this->assertNotContains(
            TeamsTemplateSource::class,
            collect(app()->tagged('email-templates.sources'))->map(fn ($s) => $s::class)->all(),
        );

        // email-templates:import builds its "Teams" source from the registry.
        $this->assertCount(4, app('email-templates.registry')->byAddon()['Teams'] ?? []);
    }
}
