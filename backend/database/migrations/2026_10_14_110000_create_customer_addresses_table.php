<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's saved addresses — "Home", "Office", or a named "Other" —
 * picked on the map in the customer app and reused from the location
 * picker and Profile → My addresses.
 *
 * customers.latitude/longitude/pincode stay the customer's *current*
 * location, which vendor matching reads. Choosing a saved address copies
 * its point there (the app calls customers/me/location); this table is the
 * address book, not a second source of truth for matching.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // home | office | other — `other_label` names an "other" one.
            $table->string('label', 16);
            $table->string('other_label', 40)->nullable();

            $table->string('apartment_number', 120);
            $table->string('building_name', 120);
            // The map/geocoder line ("Satellite Rd, Jodhpur, Ahmedabad").
            $table->string('street_address', 500);
            $table->string('area', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 120)->nullable();
            $table->string('pincode', 10)->nullable();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['customer_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
