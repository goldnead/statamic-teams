<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\Teams\Facades\Teams;
use Goldnead\Teams\Support\Users;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Foundation\Auth\User as EloquentAuthUser;
use PHPUnit\Framework\Attributes\Test;

class UserKeysAndSettingsTest extends TestCase
{
    #[Test]
    public function integer_ids_strings_and_eloquent_models_name_the_same_member(): void
    {
        $team = Teams::create('Chor');
        $model = (new EloquentAuthUser)->forceFill(['id' => 5]);

        Teams::addMember($team, 5);

        $this->assertSame('5', Users::key($model));
        $this->assertTrue($team->hasMember('5'));
        $this->assertTrue($team->hasMember(5));
        $this->assertTrue($team->hasMember($model));
        $this->assertSame('member', $team->roleOf($model));
    }

    #[Test]
    public function the_settings_are_announced_to_brand_context(): void
    {
        $registry = app(SettingsRegistry::class);

        $this->assertSame([], $registry->failures());
        $this->assertTrue($registry->has('teams'));

        $this->assertArrayHasKey('invitations.expires_after_days', $registry->fields('teams'));
        $this->assertArrayHasKey('mail.invitation.enabled', $registry->fields('teams'));
    }
}
