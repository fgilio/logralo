<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            // Null until the member sets one on their first magic-link visit.
            $table->string('password')->nullable();
            // Every cutoff in the app (day close, grace, month end) is computed
            // in this timezone, so it is required rather than derived.
            $table->string('timezone');
            // Members join through a signed link sent over WhatsApp. Only the
            // hash of the token is kept, so a database leak yields no working
            // links, and the used timestamp makes the link one-time.
            $table->string('magic_link_token')->nullable();
            $table->timestamp('magic_link_sent_at')->nullable();
            $table->timestamp('magic_link_used_at')->nullable();
            $table->timestamp('password_set_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignUlid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
