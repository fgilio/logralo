<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goals', function (Blueprint $table): void {
            $table->json('streak_pauses')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table): void {
            $table->dropColumn('streak_pauses');
        });
    }
};
