<?php

namespace Tests\Feature\Web;

use App\Models\CmsPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /pages + GET /pages/{slug} (SPEC section 5 item 13) — the real,
 * publicly-browsable web page app store submission needs, distinct
 * from a JSON API response.
 */
class CmsPageEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function page(array $overrides = []): CmsPage
    {
        return CmsPage::create(array_merge([
            'slug' => 'privacy-policy',
            'title' => 'Privacy Policy',
            'body' => '# Privacy Policy',
            'is_published' => true,
        ], $overrides));
    }

    public function test_a_published_page_renders_markdown_as_html(): void
    {
        $this->page(['body' => "# Privacy Policy\n\nWe respect **your** data."]);

        $response = $this->get('/pages/privacy-policy')->assertOk();

        $response->assertSee('<h1>Privacy Policy</h1>', false);
        $response->assertSee('<strong>your</strong>', false);
    }

    public function test_a_page_is_shown_in_the_language_asked_for(): void
    {
        $this->page([
            'title' => ['en' => 'Privacy Policy', 'gu' => 'ગોપનીયતા નીતિ'],
            'body' => ['en' => '# Privacy Policy', 'gu' => '# ગોપનીયતા નીતિ'],
        ]);

        $this->get('/pages/privacy-policy?lang=gu')
            ->assertOk()
            ->assertSee('<h1>ગોપનીયતા નીતિ</h1>', false);

        // No Hindi entered, and an unknown code: both fall back to English.
        $this->get('/pages/privacy-policy?lang=hi')->assertSee('<h1>Privacy Policy</h1>', false);
        $this->get('/pages/privacy-policy?lang=xx')->assertSee('<h1>Privacy Policy</h1>', false);
    }

    public function test_an_unpublished_page_404s(): void
    {
        $this->page(['slug' => 'terms', 'is_published' => false]);

        $this->get('/pages/terms')->assertNotFound();
    }

    public function test_a_nonexistent_slug_404s(): void
    {
        $this->get('/pages/does-not-exist')->assertNotFound();
    }

    public function test_the_index_lists_only_published_pages(): void
    {
        $published = $this->page(['slug' => 'faq', 'title' => 'FAQ', 'is_published' => true]);
        $unpublished = $this->page(['slug' => 'about', 'title' => 'About', 'is_published' => false]);

        $response = $this->get('/pages')->assertOk();

        $response->assertSee($published->title);
        $response->assertDontSee($unpublished->title);
    }

    /**
     * Content is admin-authored, not user-submitted, but rendering it
     * unescaped in the Blade view still deserves a cheap defensive
     * check that Str::markdown()'s default behaviour actually escapes
     * raw HTML rather than passing it through.
     */
    public function test_raw_html_in_the_body_is_escaped_not_executed(): void
    {
        $this->page(['body' => "# Terms\n\n<script>alert('xss')</script>"]);

        $response = $this->get('/pages/privacy-policy')->assertOk();

        $response->assertDontSee('<script>alert', false);
    }
}
