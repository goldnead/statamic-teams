<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * JSON translations are global. A key with another addon's name renamed it
 * everywhere (the Events addon read "Ereignisse" in the addon list).
 */
class TranslationsTest extends TestCase
{
    #[Test]
    public function the_json_translations_do_not_rename_core_or_other_addons(): void
    {
        $own = json_decode((string) file_get_contents(__DIR__.'/../../lang/de.json'), true);
        $core = json_decode((string) file_get_contents(__DIR__.'/../../vendor/statamic/cms/lang/de.json'), true);

        foreach (['Activity', 'Events', 'Accounts', 'Automations', 'Webhook Manager', 'Entitlements', 'Payments', 'App API'] as $name) {
            $this->assertArrayNotHasKey($name, $own, "lang/de.json must not translate the addon name \"{$name}\", use a key under teams::");
        }

        foreach ($own as $key => $value) {
            if (isset($core[$key])) {
                $this->assertSame($core[$key], $value, "lang/de.json overrides Statamic's \"{$key}\"");
            }
        }

        app()->setLocale('de');
        $this->assertSame('Ereignisse', __('teams::cp.events'));
        $this->assertSame('Webhook-Manager', __('teams::cp.webhook_manager'));
    }
}
