<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

/**
 * The package ships this migration too, but its version keys the subscribable
 * by an auto-incrementing integer and every model here is keyed by ULID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            // The vendor model has no ULID trait, so this table keeps the
            // integer key it generates.
            $table->id();
            $table->ulidMorphs('subscribable');
            // The URL of the push service that will deliver to this browser,
            // which is also the identity of the subscription.
            // ascii as the package ships it: this column is unique and long
            // enough that a utf8mb4 index over it overflows MySQL's key limit.
            // A no-op on the Postgres this actually runs on, kept so the two
            // migrations do not quietly disagree.
            $table->string('endpoint', PushSubscription::ENDPOINT_MAX_LENGTH)
                ->charset('ascii')
                ->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
