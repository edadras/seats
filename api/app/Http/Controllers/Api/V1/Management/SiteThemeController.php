<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Sites\ThemeCss;
use App\Domain\Sites\Themes;
use App\Domain\Sites\ThemeTokens;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteTheme;
use App\Models\SiteThemeVersion;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Themes an organiser makes their own.
 *
 * Two ways in, and they are the same object underneath: move the controls, or write the CSS. A
 * theme starts as a copy of one of ours — nobody wants a blank stylesheet — and from then on it is
 * tokens plus a stylesheet, sanitised on the way in (`ThemeCss`) and versioned on every save.
 *
 * Versioned because a stylesheet is the one thing an organiser can change that breaks every page
 * of a live site at once. "Put it back" has to be a click, not a support ticket.
 */
class SiteThemeController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'sites.view');

        return response()->json([
            'data' => SiteTheme::withCount('sites')->orderBy('name')->get()
                ->map(fn (SiteTheme $theme) => $this->present($theme))->values(),
            // Ours, and the vocabulary the controls are drawn from — so the panel keeps no second
            // copy of what a token is or what values it takes.
            'built_in' => array_values(Themes::all()),
            'tokens' => ThemeTokens::describe(),
            'fonts' => array_keys(ThemeTokens::FONTS),
            'max_css_bytes' => ThemeCss::MAX_BYTES,
        ]);
    }

    public function show(Request $request, SiteTheme $theme)
    {
        $this->authorize($request, 'sites.view');

        return response()->json($this->present($theme, withCss: true) + [
            'versions' => $theme->versions()->with('author')->limit(20)->get()
                ->map(fn (SiteThemeVersion $version) => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'note' => $version->note,
                    'author' => $version->author?->name,
                    'created_at' => $version->created_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'sites.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'base_key' => ['required', 'string', 'max:40'],
            'tokens' => ['sometimes', 'array'],
            'css' => ['sometimes', 'nullable', 'string'],
        ]);

        if (! Themes::exists($data['base_key'])) {
            throw ApiException::unprocessable('unknown_theme', 'That is not one of our themes.');
        }

        $theme = SiteTheme::create([
            'key' => $this->uniqueKey($data['name']),
            'name' => $data['name'],
            'base_key' => $data['base_key'],
            // A new theme starts as a copy of ours rather than as nothing: an empty token set
            // would render as the platform default, which is not what "duplicate Noir" means.
            'tokens' => ThemeTokens::sanitise($data['tokens'] ?? Themes::tokens($data['base_key'])),
            'css' => ThemeCss::sanitise($data['css'] ?? null),
            'updated_by' => $request->user()->id,
        ]);

        $this->audit->record('site_theme.created', $theme, [
            'name' => $theme->name,
            'base' => $theme->base_key,
        ]);

        return response()->json($this->present($theme, withCss: true), 201);
    }

    public function update(Request $request, SiteTheme $theme)
    {
        $this->authorize($request, 'sites.manage');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'base_key' => ['sometimes', 'string', 'max:40'],
            'tokens' => ['sometimes', 'array'],
            'css' => ['sometimes', 'nullable', 'string'],
            'note' => ['sometimes', 'nullable', 'string', 'max:160'],
        ]);

        if (isset($data['base_key']) && ! Themes::exists($data['base_key'])) {
            throw ApiException::unprocessable('unknown_theme', 'That is not one of our themes.');
        }

        $trimmed = array_key_exists('css', $data) && ThemeCss::changed($data['css']);

        DB::transaction(function () use ($theme, $data, $request) {
            // The snapshot is of what it *was*, taken before the change, which is the only order
            // that makes "put it back" mean anything.
            $theme->snapshot($data['note'] ?? null, $request->user()->id);

            $theme->fill(array_filter([
                'name' => $data['name'] ?? null,
                'base_key' => $data['base_key'] ?? null,
            ], fn ($value) => null !== $value));

            if (array_key_exists('tokens', $data)) {
                $theme->tokens = ThemeTokens::sanitise($data['tokens']);
            }

            if (array_key_exists('css', $data)) {
                $theme->css = ThemeCss::sanitise($data['css']);
            }

            $theme->updated_by = $request->user()->id;
            $theme->save();
        });

        $this->audit->record('site_theme.updated', $theme, [
            'name' => $theme->name,
            'css_bytes' => mb_strlen((string) $theme->css),
            'stylesheet_trimmed' => $trimmed,
        ]);

        return response()->json($this->present($theme->fresh(), withCss: true) + [
            // Said out loud rather than silently differing from what was typed.
            'stylesheet_trimmed' => $trimmed,
        ]);
    }

    public function revert(Request $request, SiteTheme $theme, SiteThemeVersion $version)
    {
        $this->authorize($request, 'sites.manage');

        if ($version->site_theme_id !== $theme->id) {
            throw ApiException::unprocessable('wrong_theme', 'That version belongs to another theme.');
        }

        DB::transaction(function () use ($theme, $version, $request) {
            $theme->snapshot('before revert to v'.$version->version, $request->user()->id);

            $theme->tokens = $version->tokens ?? [];
            $theme->css = $version->css;
            $theme->updated_by = $request->user()->id;
            $theme->save();
        });

        $this->audit->record('site_theme.reverted', $theme, ['to_version' => $version->version]);

        return response()->json($this->present($theme->fresh(), withCss: true));
    }

    public function destroy(Request $request, SiteTheme $theme)
    {
        $this->authorize($request, 'sites.manage');

        // A site wearing this theme would fall back to its base, which is a change to a live site
        // nobody asked for. Better to make the person deleting it move those sites first.
        $wearing = Site::where('site_theme_id', $theme->id)->count();

        if ($wearing > 0) {
            throw ApiException::conflict(
                'theme_in_use',
                'Some sites still wear this theme.',
                ['sites' => $wearing],
            );
        }

        $name = $theme->name;
        $theme->delete();

        $this->audit->record('site_theme.deleted', null, ['name' => $name]);

        return response()->json(['deleted' => true]);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function present(SiteTheme $theme, bool $withCss = false): array
    {
        $tokens = ThemeTokens::resolve(Themes::tokens($theme->base_key), (array) $theme->tokens);

        return array_filter([
            'id' => $theme->id,
            'key' => $theme->key,
            'name' => $theme->name,
            'base_key' => $theme->base_key,
            'own_tokens' => (array) $theme->tokens,
            'tokens' => $tokens,
            'token_css' => ThemeTokens::css($tokens),
            'css' => $withCss ? (string) $theme->css : null,
            'css_bytes' => mb_strlen((string) $theme->css),
            'sites_count' => $theme->sites_count ?? $theme->sites()->count(),
            'updated_at' => $theme->updated_at?->toIso8601String(),
        ], fn ($value) => null !== $value);
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::slug(mb_substr($name, 0, 40)) ?: 'theme';
        $key = $base;
        $suffix = 2;

        while (SiteTheme::where('key', $key)->exists()) {
            $key = $base.'-'.$suffix++;
        }

        return $key;
    }
}
