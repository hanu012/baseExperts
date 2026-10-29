<?php

namespace Tests\Feature\Api;

use App\Models\Category;
use App\Models\Setting;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Master-data names are stored per language and served in the language
 * the app sends as Accept-Language, falling back to English.
 */
class TranslatedMasterDataTest extends TestCase
{
    use RefreshDatabase;

    private function acService(): Category
    {
        $category = Category::factory()->create([
            'name' => ['en' => 'AC Service', 'hi' => 'एसी सर्विस'],
            'is_active' => true,
        ]);

        Subcategory::factory()->for($category)->create([
            'name' => ['en' => 'Gas Filling', 'hi' => 'गैस भरना', 'gu' => 'ગેસ ભરવો'],
            'is_active' => true,
        ]);

        return $category;
    }

    public function test_names_come_back_in_the_requested_language(): void
    {
        $this->acService();

        $this->getJson('/api/categories', ['Accept-Language' => 'hi'])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'एसी सर्विस')
            ->assertJsonPath('data.0.subcategories.0.name', 'गैस भरना');
    }

    public function test_a_missing_translation_falls_back_to_english(): void
    {
        $this->acService();

        // The category has no Gujarati name; its subcategory does.
        $this->getJson('/api/categories', ['Accept-Language' => 'gu-IN'])
            ->assertOk()
            ->assertJsonPath('data.0.name', 'AC Service')
            ->assertJsonPath('data.0.subcategories.0.name', 'ગેસ ભરવો');
    }

    public function test_no_header_or_an_unknown_language_means_english(): void
    {
        $this->acService();

        $this->getJson('/api/categories')->assertJsonPath('data.0.name', 'AC Service');
        $this->getJson('/api/categories', ['Accept-Language' => 'fr'])->assertJsonPath('data.0.name', 'AC Service');
    }

    public function test_a_language_the_admin_switched_off_is_served_in_english(): void
    {
        $this->acService();
        Setting::query()->where('key', 'enabled_languages')->update(['value' => '["en","gu"]']);

        $this->getJson('/api/categories', ['Accept-Language' => 'hi'])
            ->assertJsonPath('data.0.name', 'AC Service');
    }

    public function test_service_search_finds_a_hindi_name_and_answers_in_hindi(): void
    {
        $this->acService();

        $this->getJson('/api/services/search?q='.urlencode('गैस'), ['Accept-Language' => 'hi'])
            ->assertOk()
            ->assertJsonCount(1, 'data.services')
            ->assertJsonPath('data.services.0.name', 'गैस भरना')
            ->assertJsonPath('data.services.0.category_name', 'एसी सर्विस');
    }

    public function test_service_search_stays_case_insensitive_in_english(): void
    {
        $this->acService();

        $this->getJson('/api/services/search?q=gas')
            ->assertOk()
            ->assertJsonCount(1, 'data.services')
            ->assertJsonPath('data.services.0.name', 'Gas Filling');
    }

    /**
     * Names are stored as {"en":…,"hi":…}; a plain LIKE would match the
     * language keys themselves, so "hi" or "en" returned everything.
     */
    public function test_a_language_code_as_the_query_does_not_match_every_service(): void
    {
        $this->acService();

        foreach (['en', 'hi', 'gu'] as $code) {
            $this->getJson('/api/services/search?q='.$code)
                ->assertOk()
                ->assertJsonCount(0, 'data.services');
        }
    }
}
