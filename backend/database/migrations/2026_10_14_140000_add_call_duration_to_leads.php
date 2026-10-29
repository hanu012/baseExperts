<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approximate call length for a Call lead.
 *
 * The customer app cannot read the phone's call log (Android restricts it
 * to dialer apps; iOS has no access), so this is the time from tapping
 * Call to the customer coming back to the app — ringing plus talk. It is
 * an estimate, shown as "~3 min", not billing-grade. A call provider
 * (virtual numbers) would replace it with exact talk time later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedInteger('call_duration_seconds')->nullable()->after('channel');
            $table->timestamp('call_duration_recorded_at')->nullable()->after('call_duration_seconds');
        });

        // Admin switch for the call history in the apps (vendor Recent
        // calls, customer My calls, timing calls). On by default.
        if (! DB::table('settings')->where('key', 'call_tracking_enabled')->exists()) {
            DB::table('settings')->insert([
                'key' => 'call_tracking_enabled',
                'value' => 'true',
                'type' => 'boolean',
                'group' => 'app',
                'label' => 'Call tracking in apps',
                'description' => 'Shows Recent calls (vendor app) and My calls (customer app), and times each call approximately. Off hides them; calls still work and leads are still recorded.',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'call_tracking_enabled')->delete();

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['call_duration_seconds', 'call_duration_recorded_at']);
        });
    }
};
