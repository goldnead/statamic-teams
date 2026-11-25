<?php

namespace Goldnead\Teams\Tests\Feature;

use Goldnead\Teams\Events\TeamEvent;
use Goldnead\Teams\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\Teams\Support\Wiring;
use Goldnead\Teams\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

require_once __DIR__.'/../Fakes/webhook-manager.php';

/**
 * Stille Fehler aus dem Audit, Abschnitt Mittel (Nr. 8 und 9).
 *
 * Die Race-Befunde 5, 6 und 14 sind bewusst nicht gebaut: unter SQLite nicht
 * reproduzierbar, und die zusätzlichen Sperren würden neue Lock-Reihenfolgen
 * einführen (Review: Deadlock-Risiko gegen accept() und transferOwnership()).
 */
class SilentFailuresMidTest extends TestCase
{
    #[Test]
    public function wiring_logs_when_a_table_is_missing(): void
    {
        Log::spy();

        $wiring = app(Wiring::class);
        $safely = new ReflectionMethod($wiring, 'safely');
        $safely->setAccessible(true);

        $result = $safely->invoke($wiring, fn () => ['listeners' => 1], ['team_teststufe_fehlt']);

        $this->assertSame([], $result);
        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function webhook_dispatch_logs_a_missing_trigger(): void
    {
        Log::spy();

        $bridge = new WebhookManagerBridge;
        $dispatch = new ReflectionMethod($bridge, 'dispatch');
        $dispatch->setAccessible(true);

        $dispatch->invoke($bridge, 'teams.unbekannt', Mockery::mock(TeamEvent::class));

        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message) => str_contains($message, 'teams.unbekannt'),
        )->once();
    }
}
