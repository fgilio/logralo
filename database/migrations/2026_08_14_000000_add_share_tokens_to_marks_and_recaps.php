<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Every table that can be shared carries the same three columns. */
    private const array TABLES = ['marks', 'monthly_recaps'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                // Minted at creation rather than on the first tap: sharing on
                // iOS has to call navigator.share() inside the gesture that
                // opened it, and a round trip to mint a token would spend the
                // transient activation before the sheet ever opened.
                //
                // Nullable because null is how sharing is revoked. A revoked
                // mark can be shared again, and gets a new token when it is,
                // which is what kills the link already sent.
                $blueprint->string('share_token', 24)->nullable()->unique();
                $blueprint->unsignedInteger('share_views')->default(0);
                $blueprint->timestamp('share_last_viewed_at')->nullable();
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropUnique(['share_token']);
                $blueprint->dropColumn(['share_token', 'share_views', 'share_last_viewed_at']);
            });
        }
    }

    /**
     * Rows that existed before this migration get a token too, so the share
     * button works on the whole feed the moment it deploys rather than only on
     * marks made afterwards.
     *
     * Through the query builder rather than the models: a migration outlives
     * whatever shape those classes are in by the time it runs again.
     *
     * Paged by key rather than by offset. `each()` cuts its pages with limit
     * and offset, and every update here falsifies the `whereNull` those pages
     * are cut from — so past the first page the offset skips as many rows as
     * the last page fixed, and half the table silently keeps a null token,
     * which is indistinguishable from a revoked one. Logralo ran this over six
     * rows and never reached page two; a restore into a fuller database would.
     *
     * `lazyById` asks for ids after the last one it saw, so a shrinking filter
     * cannot move the window under it, and it never holds more than a page.
     */
    private function backfill(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)
                ->whereNull('share_token')
                ->lazyById()
                ->each(function (object $row) use ($table): void {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['share_token' => Str::random(24)]);
                });
        }
    }
};
