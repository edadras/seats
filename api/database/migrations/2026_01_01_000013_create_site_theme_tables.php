<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Themes an organiser writes for themselves.
 *
 * A custom theme is tokens plus a stylesheet — never code — so it is data, and it lives here
 * rather than on disk. Versions are kept because a stylesheet is the one thing an organiser can
 * change that breaks every page of a live site at once, and "put it back" has to be one click
 * rather than a support ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_themes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 80);
            // The first-party theme this one starts from: its stylesheet is still linked, and its
            // tokens are the floor under whatever this theme overrides.
            $table->string('base_key', 40)->default('aurora');
            $table->jsonb('tokens')->default('{}');
            $table->text('css')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('site_theme_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('site_theme_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('tokens')->default('{}');
            $table->text('css')->nullable();
            $table->string('note', 160)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['site_theme_id', 'version']);
        });

        Schema::table('sites', function (Blueprint $table) {
            // Null means the site wears a first-party theme, named by `theme_key`. A custom theme
            // is nulled rather than blocked when deleted, so a site never renders with nothing.
            $table->foreignUuid('site_theme_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_theme_id');
        });

        Schema::dropIfExists('site_theme_versions');
        Schema::dropIfExists('site_themes');
    }
};
