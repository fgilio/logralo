<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

/**
 * A browser's push endpoint, one row per device a member said yes on.
 *
 * The package ships this migration too, but its version keys the subscribable
 * by an auto-incrementing integer and every model here is keyed by ULID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            // The vendor model has no ULID trait, so this table keeps the
            // integer key it generates. Nothing points at a subscription:
            // it is found by endpoint and deleted when the push service
            // reports it gone.
            $table->id();
            $table->ulidMorphs('subscribable');
            // The URL of the push service that will deliver to this browser,
            // which is also the identity of the subscription.
            $table->string('endpoint', PushSubscription::ENDPOINT_MAX_LENGTH)->unique();
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
