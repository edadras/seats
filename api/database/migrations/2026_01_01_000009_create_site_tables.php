<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hosted event sites: an organiser's own site, on their own domain, rendered by this application.
 *
 * See `docs/adr/0003-hosted-event-sites.md`. The two rules that shape this schema:
 *
 * - A public request picks its site by Host and nothing else, so `site_domains.hostname` is unique
 *   across the whole platform and is the only routing key.
 * - Content is data, never code. A page is an ordered list of typed blocks; a theme is a key into
 *   first-party layouts. Nothing an organiser types is ever executed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();

            // The client the hosted checkout sells through. It is an ordinary api_clients row so
            // that the hosted storefront reaches the same OrderService as WooCommerce does.
            $table->foreignUuid('api_client_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('theme_key')->default('aurora');
            $table->string('locale', 12)->default('en');
            $table->string('timezone')->default('UTC');
            $table->string('currency', 3)->default('EUR');

            // Brand and theme options, validated against the theme's own schema on write.
            $table->jsonb('brand')->default('{}');

            $table->string('status')->default('draft'); // draft|live|suspended
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        /*
         * A hostname is unique platform-wide and is only routable once verified: without proof of
         * ownership, pointing DNS at us would be enough to be served on someone else's site.
         */
        Schema::create('site_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();

            $table->string('hostname')->unique();
            $table->boolean('is_primary')->default(false);

            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamps();

            $table->index(['site_id', 'verified_at']);
        });

        // Exactly one primary hostname per site: it is what canonical URLs and emails are built
        // from, so "more than one" has no meaning the application could act on.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX site_domains_one_primary
              ON site_domains (site_id) WHERE is_primary
        SQL);

        Schema::create('site_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();

            $table->string('slug');            // '' is the home page
            $table->string('title');
            $table->string('kind')->default('page'); // page|home|events|checkout — reserved routes

            // Draft and published are separate columns rather than separate rows: a page has
            // exactly two states a visitor could care about, and joining for the live one on every
            // request would be a cost paid on every page view for a feature nobody uses twice.
            $table->jsonb('draft_blocks')->default('[]');
            $table->jsonb('published_blocks')->nullable();

            $table->string('seo_title')->nullable();
            $table->string('seo_description', 320)->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'slug']);
        });

        Schema::create('site_menus', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();

            $table->string('key');            // header|footer
            $table->string('name');
            $table->timestamps();

            $table->unique(['site_id', 'key']);
        });

        Schema::create('site_menu_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('site_menu_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();

            $table->string('label');
            $table->string('target_type');    // page|event|url
            $table->foreignUuid('site_page_id')->nullable()->constrained('site_pages')->cascadeOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('url')->nullable();
            $table->boolean('new_tab')->default(false);

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['site_menu_id', 'position']);
        });

        // The self-reference goes in afterwards: inside Schema::create, Laravel emits the foreign
        // keys before the primary key, and Postgres will not point a key at a column that has no
        // unique constraint yet.
        Schema::table('site_menu_items', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('site_menu_items')->cascadeOnDelete();
        });

        // A menu item points at exactly one thing. Two targets would make the link ambiguous, and
        // none would make it dead.
        DB::statement(<<<'SQL'
            ALTER TABLE site_menu_items ADD CONSTRAINT site_menu_items_one_target CHECK (
                (target_type = 'page'  AND site_page_id IS NOT NULL AND event_id IS NULL     AND url IS NULL)
             OR (target_type = 'event' AND event_id     IS NOT NULL AND site_page_id IS NULL AND url IS NULL)
             OR (target_type = 'url'   AND url          IS NOT NULL AND site_page_id IS NULL AND event_id IS NULL)
            )
        SQL);

        Schema::table('api_clients', function (Blueprint $table) {
            // 'external' is a shop that signs its requests; 'storefront' is a hosted site of ours,
            // which is in-process and therefore has no key to sign with.
            $table->string('kind')->default('external')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::dropIfExists('site_menu_items');
        Schema::dropIfExists('site_menus');
        Schema::dropIfExists('site_pages');
        Schema::dropIfExists('site_domains');
        Schema::dropIfExists('sites');
    }
};
