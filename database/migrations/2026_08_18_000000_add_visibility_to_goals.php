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
            // 'group' or 'private'. The default keeps every existing goal
            // where it has always been: visible to the whole group.
            $table->string('visibility', 12)->default('group')->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table): void {
            $table->dropColumn('visibility');
        });
    }
};
