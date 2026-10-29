<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two business-profile fields the vendor app's Business details
 * screen edits (SPEC section 3.2) and the table did not have.
 *
 * `address` already held the street line; `city` was being folded into
 * it, which makes it useless for anything but display — a vendor's city
 * is the sort of thing search and reporting will want on its own.
 *
 * `about` is the vendor's own description of what they do, shown to
 * customers. Nullable because neither is collected at registration: a
 * salesman adding a vendor in the field captures the minimum, and the
 * vendor fills the rest in later from their own app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('city', 120)->nullable()->after('address');
            $table->text('about')->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['city', 'about']);
        });
    }
};
