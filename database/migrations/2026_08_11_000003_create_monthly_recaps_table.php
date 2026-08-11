<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_recaps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // First day of the month this recap closes. Unique, so the closing
            // command can run as often as it likes.
            $table->date('month')->unique();
            // The day the card takes in the feed: the last day of the month,
            // above that day's marks.
            $table->date('posted_on');
            $table->foreignUlid('winner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('runner_up_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('best_streak_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('best_streak_goal_id')->nullable()->constrained('goals')->nullOnDelete();
            $table->unsignedSmallInteger('best_streak_days')->default(0);
            // Frozen final standings: scores are never recomputed after close,
            // so archiving a goal in September cannot rewrite August.
            $table->json('standings');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_recaps');
    }
};
