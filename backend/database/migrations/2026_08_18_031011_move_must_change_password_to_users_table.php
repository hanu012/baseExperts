<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves must_change_password from `salesmen` to `users`.
 *
 * SPEC section 2.1 requires a forced password change on a salesman's first
 * login, which is why the flag started on `salesmen`. But UserResource issues
 * a temporary password to EVERY role it creates — admin, salesman, vendor and
 * customer alike — and SPEC section 2.2 has a salesman handing a vendor their
 * temp password over WhatsApp. A salesman-only flag therefore leaves three
 * roles holding an admin-chosen password indefinitely, with nothing prompting
 * a change.
 *
 * On `users` it is one column, checked once, working for every flavour's
 * login. The old column is dropped rather than left in place: a duplicate
 * that still reads as authoritative is exactly the thing someone wires up by
 * mistake later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });

        // Carry existing salesmen across before the column goes.
        DB::statement('
            UPDATE users
            INNER JOIN salesmen ON salesmen.user_id = users.id
            SET users.must_change_password = salesmen.must_change_password
        ');

        Schema::table('salesmen', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('salesmen', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(true)->after('phone');
        });

        DB::statement('
            UPDATE salesmen
            INNER JOIN users ON salesmen.user_id = users.id
            SET salesmen.must_change_password = users.must_change_password
        ');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
