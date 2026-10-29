<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Firebase sign-in (see App\Services\AuthProvider).
 *
 *  - users.firebase_uid — the user's Firebase account, once there is one.
 *    The users row stays the record of the account either way.
 *  - settings.auth_provider — laravel (default: nothing changes) or
 *    firebase. Not in SettingSeeder, for the same reason as
 *    password_reset_method: its updateOrCreate would reset an admin's choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('firebase_uid', 128)->nullable()->unique()->after('password');
        });

        if (DB::table('settings')->where('key', 'auth_provider')->doesntExist()) {
            DB::table('settings')->insert([
                'key' => 'auth_provider',
                'value' => 'laravel',
                'type' => 'string',
                'group' => 'auth',
                'label' => 'Sign-in provider',
                'description' => 'laravel or firebase — who checks app passwords and sends password emails.',
                'is_editable' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'auth_provider')->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['firebase_uid']);
            $table->dropColumn('firebase_uid');
        });
    }
};
