<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('mark_id')->constrained()->cascadeOnDelete();
            // Denormalised from nothing — a comment is written by whoever is
            // looking, not by the mark's owner — so this is the only place the
            // author is recorded.
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            // A line under a photo, not a post. The cap is in config and the
            // Action enforces it too; the column is what makes it true.
            $table->string('body', 280);
            $table->timestamps();

            // The thread is always read whole and in the order it was written.
            $table->index(['mark_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
