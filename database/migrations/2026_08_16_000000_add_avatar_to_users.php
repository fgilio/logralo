<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // The directory the member's uploaded avatar lives under on the
            // photo disk, in the same shape a mark's photo_key has. Null is
            // the common case: the browser then tries Gravatar, and the
            // coloured initials are what shows when that has nothing either.
            $table->string('avatar_key')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('avatar_key');
        });
    }
};
