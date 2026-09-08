<?php

namespace App\Modules;

use App\Models\ModuleFailure;
use App\Models\TenantModule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What is installed on this server, what each organiser has switched on, and what each of those
 * contributes (ADR-0004).
 *
 * Two questions that look like one and are not:
 *
 *   **installed** is a property of the deployment, discovered from the `modules/` directory. An
 *   operator decides it, by deploying.
 *   **enabled** is a property of a tenant, stored in `tenant_modules`. An organiser decides it, in
 *   the panel.
 *
 * Conflating them is how a platform ends up running code one customer chose inside another
 * customer's request.
 */
class ModuleRegistry
{
    /** @var array<string, ModuleManifest>|null */
    private ?array $installed = null;

    /** @var array<string, ModuleProvider> Providers built for the current tenant, by module key. */
    private array $providers = [];

    private ?string $providersFor = null;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ModuleSettings $settings,
    ) {}

    /* ------------------------------------------------------------------ what is installed */

    /** @return array<string, ModuleManifest> */
    public function installed(): array
    {
        if (null !== $this->installed) {
            return $this->installed;
        }

        $manifests = [];

        foreach ($this->manifestFiles() as $file) {
            $data = json_decode((string) file_get_contents($file), true);

            if (! is_array($data)) {
                // A broken manifest is an operator's problem and must be loud, but it must not stop
                // the platform booting: one bad module would take every tenant offline.
                logger()->error('Unreadable module manifest.', ['file' => $file]);

                continue;
            }

            try {
                $manifest = ModuleManifest::fromArray($data, dirname($file));
                $manifests[$manifest->key] = $manifest;
            } catch (Throwable $e) {
                logger()->error('Invalid module manifest.', ['file' => $file, 'error' => $e->getMessage()]);
            }
        }

        ksort($manifests);

        return $this->installed = $manifests;
    }

    public function isInstalled(string $key): bool
    {
        return isset($this->installed()[$key]);
    }

    public function manifest(string $key): ?ModuleManifest
    {
        return $this->installed()[$key] ?? null;
    }

    /* --------------------------------------------------------------- what a tenant has on */

    /** @return array<string, ModuleManifest> */
    public function enabled(): array
    {
        $tenantId = $this->tenantContext->id();

        if (! $tenantId) {
            return [];
        }

        $keys = $this->enabledKeys($tenantId);

        return array_filter(
            $this->installed(),
            fn (string $key) => in_array($key, $keys, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    public function isEnabled(string $key): bool
    {
        $tenantId = $this->tenantContext->id();

        return $tenantId
            && $this->isInstalled($key)
            && in_array($key, $this->enabledKeys($tenantId), true);
    }

    /**
     * Cached per tenant, because this is asked on nearly every request that renders a checkout.
     * Short-lived and explicitly forgotten on a change, so switching a module off takes effect now
     * rather than in five minutes — the reason someone switches a payment module off is usually
     * that it is doing something they want stopped.
     *
     * @return list<string>
     */
    private function enabledKeys(string $tenantId): array
    {
        return Cache::remember(
            self::cacheKey($tenantId),
            now()->addMinutes(5),
            function () use ($tenantId) {
                $decided = TenantModule::withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->get(['module_key', 'enabled'])
                    ->pluck('enabled', 'module_key');

                $keys = $decided->filter()->keys()->all();

                /*
                 * A module marked `auto_enable` is on for an organiser who has never said
                 * otherwise, so a brand-new account can take a booking without first being sent to
                 * a settings screen. An explicit "off" is a row with enabled = false, and it wins:
                 * a decision already made is never re-made for somebody.
                 */
                foreach ($this->installed() as $key => $manifest) {
                    if ($manifest->autoEnable && ! $decided->has($key)) {
                        $keys[] = $key;
                    }
                }

                return array_values(array_unique($keys));
            }
        );
    }

    public static function forget(string $tenantId): void
    {
        Cache::forget(self::cacheKey($tenantId));
    }

    private static function cacheKey(string $tenantId): string
    {
        return 'modules:enabled:'.$tenantId;
    }

    /* ------------------------------------------------------------------------- extensions */

    /**
     * Everything the tenant's enabled modules contribute to one extension point.
     *
     * A module that throws while being asked is recorded and skipped. The alternative is that one
     * broken module takes down a checkout page, which is a worse answer to "this module is broken"
     * than "this module is not here".
     *
     * @return list<mixed>
     */
    public function contributions(string $point): array
    {
        $contributions = [];

        foreach ($this->enabled() as $key => $manifest) {
            if (! $manifest->extendsPoint($point)) {
                continue;
            }

            $provider = $this->provider($key);

            if (! $provider) {
                continue;
            }

            try {
                foreach ((array) $provider->{$point}() as $contribution) {
                    $contributions[] = $contribution;
                }
            } catch (Throwable $e) {
                $this->recordFailure($key, $point, $point, $e);
            }
        }

        return $contributions;
    }

    /** The provider for one enabled module, built with that tenant's settings. */
    public function provider(string $key): ?ModuleProvider
    {
        $tenantId = $this->tenantContext->id();

        // Providers hold a tenant's decrypted settings, so the cache is thrown away the moment the
        // tenant changes. A provider built for one organiser must never answer for another.
        if ($this->providersFor !== $tenantId) {
            $this->providers = [];
            $this->providersFor = $tenantId;
        }

        if (isset($this->providers[$key])) {
            return $this->providers[$key];
        }

        $manifest = $this->manifest($key);

        if (! $manifest) {
            return null;
        }

        try {
            $context = new ModuleContext(
                manifest: $manifest,
                tenantId: $tenantId,
                settings: $this->settings->resolve($manifest, $tenantId),
            );

            return $this->providers[$key] = new $manifest->provider($context);
        } catch (Throwable $e) {
            $this->recordFailure($key, 'boot', 'construct', $e);

            return null;
        }
    }

    /**
     * Write down what a module did wrong.
     *
     * Swallowed so the request survives, recorded so it is not silent: a messaging module that has
     * quietly stopped sending tickets looks exactly like one that is working (ADR-0004 §5).
     */
    public function recordFailure(string $key, string $point, ?string $operation, Throwable $e): void
    {
        $tenantId = $this->tenantContext->id();

        logger()->error('Module failed.', [
            'module' => $key,
            'point' => $point,
            'operation' => $operation,
            'error' => $e->getMessage(),
        ]);

        if (! $tenantId) {
            return;
        }

        try {
            ModuleFailure::create([
                'tenant_id' => $tenantId,
                'module_key' => $key,
                'extension_point' => $point,
                'operation' => $operation,
                'message' => mb_substr($e->getMessage(), 0, 2000),
                'context' => ['class' => $e::class],
                'created_at' => now(),
            ]);

            // Enough of these and the module goes off, with a reason the organiser can read.
            // Being off is visible; being broken is not (ADR-0004 §5).
            app(ModuleHealth::class)->reviewAfterFailure($key);
        } catch (Throwable) {
            // Recording a failure must not itself fail a request. The log line above is the floor.
        }
    }

    /** @return list<string> */
    private function manifestFiles(): array
    {
        $root = config('seatmap.modules.path') ?: base_path('../modules');

        if (! is_dir($root)) {
            return [];
        }

        // Exactly two levels: modules/<Vendor>/<Name>/module.json. Not a recursive scan, so a
        // manifest that wandered into a vendor directory or a test fixture is not picked up.
        return array_values(array_filter(
            glob($root.'/*/*/module.json') ?: [],
            'is_file'
        ));
    }
}
