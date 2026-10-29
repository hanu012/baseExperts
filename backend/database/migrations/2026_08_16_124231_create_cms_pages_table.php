<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMS pages (SPEC section 5.13): Terms, Privacy Policy, Refund Policy, FAQ,
 * About.
 *
 * Required for app store listing — both stores reject a submission whose
 * privacy policy URL does not resolve, so these need to be live and publicly
 * readable before the first submission, not after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_pages', function (Blueprint $table) {
            $table->id();

            // terms, privacy-policy, refund-policy, faq, about — the public
            // URL and the key the apps link to.
            $table->string('slug')->unique();

            $table->string('title');
            $table->longText('body')->nullable();

            // Null means the page is shown in every app.
            $table->enum('target_app', ['salesman', 'vendor', 'customer'])->nullable();

            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['is_published', 'target_app']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_pages');
    }
};
