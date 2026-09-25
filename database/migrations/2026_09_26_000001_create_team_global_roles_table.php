<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global roles as the Control Panel changes them.
 *
 * `teams.roles` in the config stays the starting point. A row here wins
 * over the config role of the same handle, or adds a role the config does
 * not know. `removed` keeps a deleted config role deleted: without it, the
 * config would bring it back on the next request. Deleting the row is
 * "reset to default".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_global_roles', function (Blueprint $table) {
            $table->id();
            $table->string('handle', 64)->unique();
            $table->string('label');
            $table->json('permissions')->nullable();
            $table->boolean('removed')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_global_roles');
    }
};
