<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            // Where the member was standing when they opened the sheet. Half
            // of what somebody reports is only legible next to the screen it
            // happened on, and nobody types "en el perfil" themselves.
            $table->string('page', 120)->nullable();
            $table->timestamps();

            // Read newest-first, always.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
