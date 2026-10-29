<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * API error log: one row per failed api/* call, written by the LogApiErrors
 * middleware while admin Settings → Error log is on, shown on System → API
 * error log. Pruned after 30 days (ApiErrorLog::prunable()).
 *
 * The request payload is stored with passwords, tokens and secrets blanked
 * (LogApiErrors::redact()); the Authorization header is never stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_error_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedSmallInteger('status');
            $table->string('method', 10);
            $table->string('path', 500);

            // The API's own envelope: error.code / error.message.
            $table->string('error_code', 100)->nullable();
            $table->text('message')->nullable();

            // Present when an exception produced the response.
            $table->string('exception_class')->nullable();
            $table->string('exception_location')->nullable();
            $table->text('trace')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('request_payload')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });

        foreach ([
            [
                'key' => 'api_error_log_enabled',
                'value' => 'false',
                'type' => 'boolean',
                'label' => 'API error log',
                'description' => 'Record failed API calls and show them on System → API error log.',
            ],
            [
                'key' => 'api_error_log_level',
                'value' => 'server',
                'type' => 'string',
                'label' => 'API errors to record',
                'description' => 'server (5xx only) or all (4xx and 5xx).',
            ],
        ] as $row) {
            if (! DB::table('settings')->where('key', $row['key'])->exists()) {
                DB::table('settings')->insert($row + [
                    'group' => 'system',
                    'is_editable' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_error_logs');

        DB::table('settings')->whereIn('key', ['api_error_log_enabled', 'api_error_log_level'])->delete();
    }
};
