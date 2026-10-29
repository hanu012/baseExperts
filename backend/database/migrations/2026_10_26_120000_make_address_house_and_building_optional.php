<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * House/flat number and building name become optional on a saved address:
 * a villa often has no building name, and many customers set their
 * location from the map pin alone. The pinned point and street line stay
 * required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->string('apartment_number', 120)->nullable()->change();
            $table->string('building_name', 120)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->string('apartment_number', 120)->nullable(false)->default('')->change();
            $table->string('building_name', 120)->nullable(false)->default('')->change();
        });
    }
};
