<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * IMPORTANT NOTES:
     *
     * 1. Schema follows the exact specification from the case study.
     *
     * 2. Indexes:
     *    - IDX_logs_sms: Original index from case study (provider, status, priority, id)
     *    - IDX_cart_created_at: Original index for created_at queries
     *    - IDX_logs_sms_order_id: Original index for parent lookups
     *    - IDX_sms_optimized_query: ADDITIONAL index for Step 6 optimization
     *
     * 3. Timezone Warning:
     *    The case study specifies "Australia/Tasmania" as a valid timezone, but this
     *    is NOT a valid timezone in MySQL's tz tables. CONVERT_TZ() will return NULL
     *    for this value, silently dropping those rows from query results.
     *    The correct timezone is "Australia/Hobart".
     *
     *    If populating data from the case study spec, you may need to correct this:
     *    UPDATE logs_sms SET time_zone = 'Australia/Hobart' WHERE time_zone = 'Australia/Tasmania';
     */
    public function up(): void
    {
        Schema::create('logs_sms', function (Blueprint $table) {
            $table->increments('id');
            $table->enum('parent_table', ['cart_order', 'reservation', 'marketing_campaign'])->nullable();
            $table->unsignedInteger('parent_id')->nullable();
            $table->string('phone', 100);
            $table->mediumText('message');
            $table->tinyInteger('priority')->default(0);
            $table->string('device_id', 255)->nullable();
            $table->float('cost')->default(0);
            $table->unsignedTinyInteger('sent')->default(0);
            $table->unsignedTinyInteger('delivered')->default(0);
            $table->text('error')->nullable();
            $table->enum('provider', [
                'inhousesms',
                'wholesalesms',
                'prowebsms',
                'onverify',
                'inhousesms-nz',
                'inhousesms-my',
                'inhousesms-au',
                'inhousesms-au-marketing',
                'inhousesms-nz-marketing'
            ]);
            $table->tinyInteger('status')->default(0);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('send_after')->nullable();
            $table->string('time_zone', 55)->nullable();

            // Original indexes from case study (Step 2)
            // These are the exact indexes specified in the case study requirements
            $table->index(['provider', 'status', 'priority', 'id'], 'IDX_logs_sms');
            $table->index(['created_at'], 'IDX_cart_created_at');
            $table->index(['parent_table', 'parent_id'], 'IDX_logs_sms_order_id');

            // ADDITIONAL optimized composite index for actionGetMessagesToSend (Step 6)
            //
            // Why add this index:
            // - The original IDX_logs_sms starts with (provider, status, priority, id)
            // - Our query filters: status=0, provider=X, send_after <= NOW()
            // - The original index requires filtering provider first, but our query
            //   benefits more from filtering status first (50k pending vs 1M+ sent)
            //
            // Index order rationale:
            // - status (equality): Filters down to ~50k rows immediately
            // - provider (equality): Further narrows to specific provider
            // - send_after (range): Range scan for messages ready to send
            // - id (ordering): Avoids additional sort for ORDER BY id ASC
            //
            // Note: priority is intentionally NOT in this index because:
            // 1. Adding ORDER BY priority DESC, id ASC prevents using id in the index for ordering
            // 2. The filtered result set is small enough (~10-100 rows) that sorting is cheap
            // 3. If priority ordering becomes a bottleneck, consider (status, provider, send_after, priority, id)
            $table->index(['status', 'provider', 'send_after', 'id'], 'IDX_sms_optimized_query');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs_sms');
    }
};

