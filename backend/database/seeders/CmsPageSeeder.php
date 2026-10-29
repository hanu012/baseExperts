<?php

namespace Database\Seeders;

use App\Models\CmsPage;
use Illuminate\Database\Seeder;

/**
 * The 5 pages SPEC section 5.13 lists, seeded as unpublished
 * placeholders so their public URLs exist and resolve immediately
 * after a fresh `migrate:fresh --seed` — an admin fills in real
 * content and flips is_published once it's ready.
 */
class CmsPageSeeder extends Seeder
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private const PAGES = [
        [
            'slug' => 'terms',
            'title' => 'Terms of Service',
            'body' => "# Terms of Service\n\nContent pending.",
        ],
        [
            'slug' => 'privacy-policy',
            'title' => 'Privacy Policy',
            'body' => "# Privacy Policy\n\nContent pending.",
        ],
        [
            'slug' => 'refund-policy',
            'title' => 'Refund Policy',
            'body' => "# Refund Policy\n\nContent pending.",
        ],
        [
            'slug' => 'faq',
            'title' => 'Frequently Asked Questions',
            'body' => "# FAQ\n\nContent pending.",
        ],
        [
            'slug' => 'about',
            'title' => 'About Us',
            'body' => "# About Us\n\nContent pending.",
        ],
    ];

    public function run(): void
    {
        foreach (self::PAGES as $definition) {
            CmsPage::updateOrCreate(
                ['slug' => $definition['slug']],
                $definition + ['is_published' => false]
            );
        }

        $this->command?->info('CMS pages: '.CmsPage::count().' pages seeded.');
    }
}
