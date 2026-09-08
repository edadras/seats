<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\TenantModule;
use App\Modules\ModuleHealth;
use App\Modules\ModuleManifest;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleSettings;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * The panel's side of modules (ADR-0004).
 *
 * Note the two things this controller cannot do, both on purpose:
 *
 *   it cannot install a module — what runs on a server is an operator's decision, made by
 *   deploying, and an endpoint that changed it would be a remote-code-execution feature;
 *   it cannot read a secret back — the answer is "set" or "not set", and an offer to replace. A
 *   gateway secret readable from a panel session is a gateway secret a stolen session has.
 */
class ModuleController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleSettings $settings,
        private readonly ModuleHealth $health,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {}

    public function index()
    {
        $modules = array_map(
            fn (ModuleManifest $manifest) => $this->present($manifest),
            array_values($this->registry->installed())
        );

        return response()->json([
            'data' => $modules,
            // The panel labels each extension point; sending the list means it does not carry its
            // own copy of what a module can extend.
            'points' => ModuleManifest::POINTS,
        ]);
    }

    public function show(string $key)
    {
        return response()->json($this->present($this->find($key), withFailures: true));
    }

    /**
     * Turn a module on or off, and save what it needs.
     *
     * One endpoint rather than three, because these are one decision: an organiser filling in an
     * API key and switching a gateway on has done a single thing, and splitting it produces the
     * state nobody wants — enabled and unconfigured, taking bookings it cannot settle.
     */
    public function update(Request $request, string $key)
    {
        $this->authorizeWrite($request);

        $manifest = $this->find($key);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
        ]);

        $tenantId = $this->tenantContext->id();

        $row = TenantModule::firstOrNew(['module_key' => $manifest->key]);
        $existing = (array) ($row->settings ?? []);

        if (array_key_exists('settings', $data)) {
            $row->settings = $this->settings->normalise($manifest, $data['settings'], $existing);
        }

        if (array_key_exists('enabled', $data)) {
            $row->enabled = (bool) $data['enabled'];

            // Turning it back on clears the platform's own "we switched this off" note, so the
            // panel does not go on telling an organiser about a problem they have just fixed.
            $row->disabled_reason = null;
            $row->disabled_at = null;
        }

        $row->tenant_id = $tenantId;
        $row->save();

        // Asked after saving, with the settings it will actually run with: a module is the only
        // thing that knows whether what it was given is enough to work.
        if ($row->enabled) {
            ModuleRegistry::forget($tenantId);

            $problems = $this->registry->provider($manifest->key)?->validate() ?? [];

            if ($problems) {
                $row->forceFill(['enabled' => false])->save();
                ModuleRegistry::forget($tenantId);

                throw ApiException::unprocessable(
                    'module_not_configured',
                    'Fill in what this module needs before turning it on.',
                    ['problems' => array_map(fn (string $problem) => __($problem), $problems)],
                );
            }
        }

        ModuleRegistry::forget($tenantId);

        $this->audit->record($row->enabled ? 'module.enabled' : 'module.disabled', $row, [
            'module' => $manifest->key,
            // Which settings were written, never what they were: this log is read by people.
            'settings_changed' => array_keys(array_diff_key((array) $row->settings, $existing)),
        ]);

        return response()->json($this->present($manifest, withFailures: true));
    }

    private function find(string $key): ModuleManifest
    {
        // The key arrives as `vendor.name` in the path, because a slash in a path segment is a
        // fight with every router and proxy between here and the browser.
        $manifest = $this->registry->manifest(str_replace('.', '/', $key));

        if (! $manifest) {
            throw ApiException::notFound('That module is not installed on this server.', 'module_not_installed');
        }

        return $manifest;
    }

    private function present(ModuleManifest $manifest, bool $withFailures = false): array
    {
        $tenantId = $this->tenantContext->id();

        $row = TenantModule::where('module_key', $manifest->key)->first();

        $data = [
            'key' => $manifest->key,
            'vendor' => $manifest->vendor,
            'version' => $manifest->version,
            'first_party' => $manifest->firstParty,
            'name' => __($manifest->nameKey),
            'description' => __($manifest->descriptionKey),
            'extends' => $manifest->extends,
            'enabled' => $this->registry->isEnabled($manifest->key),
            'auto_enabled' => $manifest->autoEnable && ! $row,
            'disabled_reason' => $row?->disabled_reason,
            'settings' => array_map(
                fn (array $setting) => $setting + [
                    'label' => __($setting['label_key']),
                    'hint' => $setting['hint_key'] ? __($setting['hint_key']) : null,
                ],
                $manifest->settings
            ),
            // Values, with every secret reported as set or not set and never as itself.
            'values' => $this->settings->present($manifest, $tenantId),
        ];

        if ($withFailures) {
            $data['health'] = $this->health->forModule($manifest->key);
        }

        return $data;
    }
}
