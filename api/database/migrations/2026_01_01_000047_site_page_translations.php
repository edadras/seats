<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A site written in more than one language.
 *
 * An event's own words have been translatable since ADR-0005 was applied to them, and the platform
 * chrome around them speaks six languages. What was left in one language was everything the
 * organiser wrote *themselves* — the About page, the visiting directions, the words in the header
 * menu — which on a Persian venue's site is the half a visitor actually reads.
 *
 * **The switcher used to offer six languages and mean none of them.** Every hosted site listed all
 * six in its footer whatever the organiser had written, so a visitor could pick Italian and be
 * handed a Persian page with English furniture. `sites.locales` is the honest answer: the languages
 * this site is actually published in, and the only ones offered.
 *
 * **A translation is an overlay, never a copy of the page.** `site_pages.translations` holds, per
 * locale, a title, the two SEO lines, and the text of individual blocks keyed by block id — not a
 * second block tree. A copied tree drifts: somebody adds a section to the English page and the
 * Persian one keeps the old shape for ever, and nothing in the editor can tell them so. An overlay
 * cannot drift, because the shape only exists once.
 *
 * **A missing translation falls back to the original**, field by field rather than page by page. A
 * half-translated page is a page with some English on it, which is what a reader would rather have
 * than a page with holes in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // The languages this site is published in. Its own `locale` is always one of them,
            // enforced by the application rather than a constraint: it is a list to be edited,
            // and a database that refused a temporary state would make editing it a fight.
            $table->jsonb('locales')->default('[]')->after('locale');
        });

        Schema::table('site_pages', function (Blueprint $table) {
            $table->jsonb('translations')->default('{}')->after('seo_description');
        });

        // The words in the header and the footer, which are the first thing a visitor reads and
        // were the last thing on the site that could not be said in their language.
        Schema::table('site_menu_items', function (Blueprint $table) {
            $table->jsonb('translations')->default('{}')->after('label');
        });

        // Every existing site is published in the language it was written in, which is what its
        // switcher was already implying and now actually means.
        DB::statement("UPDATE sites SET locales = jsonb_build_array(locale) WHERE locale IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('site_menu_items', function (Blueprint $table) {
            $table->dropColumn('translations');
        });

        Schema::table('site_pages', function (Blueprint $table) {
            $table->dropColumn('translations');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('locales');
        });
    }
};
