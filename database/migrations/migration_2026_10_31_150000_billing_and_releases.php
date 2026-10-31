<?php

use Spark\Database\Schema\Blueprint;
use Spark\Facades\{DB, Schema};

return new class {
    public function up(): void
    {
        Schema::table('pending_uploads', function (Blueprint $table): void {
            $table->boolean('private_release')->default(false);
        });

        Schema::table('videos', function (Blueprint $table): void {
            $table->string('processing_state', 20)->default('processing');
            $table->string('publication_state', 20)->default('draft');
            $table->string('publication_intent', 20)->default('immediate');
            $table->timestamp('publish_at')->nullable();
            $table->string('publish_timezone', 80)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('release_error', 80)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->index(['publication_state', 'publish_at', 'id'], 'videos_due_releases');
        });

        // Historical scheduled_at metadata never constituted approval to schedule.
        DB::table('videos')->where('status', 'published')->update([
            'processing_state' => 'ready', 'publication_state' => 'published',
            'published_at' => DB::raw('created_at'),
        ]);
        $legacyTimezone = new DateTimeZone(config('app.timezone', 'UTC'));
        if ($legacyTimezone->getName() !== 'UTC') {
            $last = 0;
            do {
                $rows = DB::table('videos')->where('status', 'published')->whereNotNull('created_at')
                    ->where('id', '>', $last)->orderBy('id')->limit(500)->get();
                foreach ($rows as $row) {
                    $last = $row->id;
                    $published = (new DateTimeImmutable($row->created_at, $legacyTimezone))->setTimezone(new DateTimeZone('UTC'));
                    DB::table('videos')->where('id', $row->id)->update(['published_at' => $published->format('Y-m-d H:i:s')]);
                }
            } while (count($rows) === 500);
        }
        DB::table('videos')->whereIn('status', ['failed', 'source_unavailable', 'deleted'])
            ->update(['processing_state' => 'failed']);

        Schema::create('billing_catalog', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 20);
            $table->string('environment', 10);
            $table->string('plan_code', 40);
            $table->string('offer_version', 40);
            $table->string('price_id', 120);
            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('usd');
            $table->string('interval', 10)->default('month');
            $table->timestamps();
            $table->unique(['provider', 'environment', 'plan_code', 'offer_version'], 'billing_catalog_version');
            $table->unique(['provider', 'environment', 'price_id'], 'billing_catalog_price');
        });

        Schema::create('billing_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->string('provider', 20);
            $table->string('environment', 10);
            $table->string('reference', 64)->unique();
            $table->string('customer_id', 120)->nullable();
            $table->timestamp('request_started_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider', 'environment'], 'billing_customer_owner');
            $table->unique(['provider', 'environment', 'customer_id'], 'billing_customer_provider');
        });

        Schema::create('billing_checkouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->foreignId('customer_id')->constrained('billing_customers');
            $table->foreignId('catalog_id')->constrained('billing_catalog');
            $table->string('provider', 20);
            $table->string('environment', 10);
            $table->string('reference', 64)->unique();
            $table->string('idempotency_hash', 64);
            $table->string('request_hash', 64);
            $table->string('active_key', 64)->nullable()->unique();
            $table->string('status', 20)->default('pending');
            $table->string('session_id', 120)->nullable();
            $table->text('url')->nullable();
            $table->text('payload')->nullable();
            $table->timestamp('request_started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_hash'], 'billing_checkout_idempotency');
            $table->unique(['provider', 'environment', 'session_id'], 'billing_checkout_session');
        });

        Schema::create('billing_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->setNullOnDelete();
            $table->foreignId('checkout_id')->constrained('billing_checkouts');
            $table->unique('checkout_id');
            $table->foreignId('customer_id')->constrained('billing_customers');
            $table->foreignId('catalog_id')->constrained('billing_catalog');
            $table->string('provider', 20);
            $table->string('environment', 10);
            $table->string('subscription_id', 120);
            $table->string('active_key', 64)->nullable()->unique();
            $table->string('status', 30);
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('period_end')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->string('renewal_operation', 64)->nullable();
            $table->boolean('renewal_target')->nullable();
            $table->timestamp('renewal_started_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'environment', 'subscription_id'], 'billing_subscription_provider');
        });

        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('billing_subscriptions');
            $table->string('provider', 20);
            $table->string('environment', 10);
            $table->string('invoice_id', 120);
            $table->string('charge_id', 120)->nullable();
            $table->string('status', 30);
            $table->unsignedInteger('amount_paid');
            $table->unsignedInteger('amount_due');
            $table->string('currency', 3);
            $table->text('hosted_url')->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->unique(['provider', 'environment', 'invoice_id'], 'billing_invoice_provider');
        });

        Schema::create('billing_paid_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('billing_subscriptions');
            $table->foreignId('invoice_id')->constrained('billing_invoices');
            $table->unique('invoice_id');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('boost_credit_cents')->default(0);
            $table->boolean('promotion_hold')->default(false);
            $table->timestamps();
            $table->unique(['subscription_id', 'starts_at', 'ends_at'], 'billing_service_period');
        });

        Schema::create('billing_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 20);
            $table->string('environment', 10);
            $table->string('event_id', 120);
            $table->string('type', 100);
            // Encryption expands the accepted 1 MiB body beyond MySQL TEXT capacity.
            $table->longText('payload')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'environment', 'event_id'], 'billing_webhook_provider');
            $table->index(['status', 'updated_at']);
        });

        Schema::create('billing_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('billing_invoices');
            $table->string('provider', 20);
            $table->string('environment', 10);
            $table->string('object_id', 120);
            $table->string('kind', 20);
            $table->string('status', 40);
            $table->unsignedInteger('amount');
            $table->string('currency', 3);
            $table->string('allocation', 30)->default('review_required');
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'environment', 'object_id'], 'billing_adjustment_provider');
        });
    }

    public function down(): void
    {
        // Legacy workers would automatically publish even an unencoded private draft.
        if (DB::table('videos')->whereIn('status', ['draft', 'scheduled'])->exists()
            || DB::table('videos')->where('publication_intent', 'draft')->where('publication_state', '!=', 'published')->exists()) {
            throw new RuntimeException('Resolve draft/scheduled releases before rollback.');
        }

        foreach (['billing_adjustments', 'billing_webhooks', 'billing_paid_periods', 'billing_invoices', 'billing_subscriptions', 'billing_checkouts', 'billing_customers', 'billing_catalog'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex('videos_due_releases');
            $table->dropColumn(['processing_state', 'publication_state', 'publication_intent', 'publish_at', 'publish_timezone', 'published_at', 'release_error', 'version']);
        });
        Schema::table('pending_uploads', fn(Blueprint $table) => $table->dropColumn('private_release'));
    }
};
