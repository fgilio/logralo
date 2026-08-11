<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('goal_id')->constrained()->cascadeOnDelete();
            // Denormalised from the goal so the group feed and the monthly
            // score never have to join through goals.
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            // The member's own local day, not a UTC timestamp. A day accepts
            // marks from 00:00 until 12:00 of the next day, then closes.
            $table->date('marked_on');
            // Null photo means a ghost mark: it keeps the streak alive but
            // scores half, and the feed shows the ghost treatment.
            //
            // The key is the directory every derivative lives under, so the
            // set of sizes can change without a migration.
            $table->string('photo_key')->nullable();
            $table->unsignedSmallInteger('photo_width')->nullable();
            $table->unsignedSmallInteger('photo_height')->nullable();
            $table->string('note', 140)->nullable();
            $table->timestamps();

            $table->unique(['goal_id', 'marked_on']);
            $table->index(['marked_on', 'created_at']);
            $table->index(['user_id', 'marked_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marks');
    }
};
