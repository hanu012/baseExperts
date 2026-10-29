<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use App\Support\Languages;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Public CMS pages (SPEC section 5 item 13) — the actual web page an
 * app store reviewer or user can open in a browser. Deliberately NOT
 * under the Api namespace: this returns Blade/HTML, not JSON.
 *
 * A browser link carries no app header, so the language is a query
 * parameter instead: /pages/terms?lang=gu. Missing or disabled → English.
 */
class PageController extends Controller
{
    public function index(Request $request): View
    {
        $this->useRequestedLanguage($request);

        $pages = CmsPage::published()->orderBy('title')->get();

        return view('pages.index', ['pages' => $pages]);
    }

    public function show(Request $request, string $slug): View
    {
        $this->useRequestedLanguage($request);

        $page = CmsPage::published()->where('slug', $slug)->firstOrFail();

        return view('pages.show', [
            'page' => $page,
            'html' => Str::markdown((string) $page->body),
        ]);
    }

    private function useRequestedLanguage(Request $request): void
    {
        $language = $request->query('lang');

        app()->setLocale(
            is_string($language) && in_array($language, Languages::enabled(), true)
                ? $language
                : Languages::ENGLISH
        );
    }
}
