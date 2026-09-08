<?php

namespace App\Modules;

use App\Models\TenantModule;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Typed, validated, per-tenant module configuration — and the rule that a secret goes in and never
 * comes back out (ADR-0004 §3).
 *
 * A gateway secret that can be read back from a panel session is a gateway secret that a stolen
 * panel session has. So the API answers "set" or "not set", and offers to replace. There is no
 * endpoint that returns one, and this class is why: nothing outside `resolve()` ever decrypts.
 */
class ModuleSettings
{
    /**
     * Validate and normalise what an organiser typed, ready to store.
     *
     * Existing secrets survive an update that does not mention them, so saving a form that shows
     * "•••• set" does not wipe the key it is describing.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $existing  The stored settings, as they are on disk.
     * @return array<string, mixed>
     */
    public function normalise(ModuleManifest $manifest, array $input, array $existing = []): array
    {
        $stored = [];
        $errors = [];

        foreach ($manifest->settings as $setting) {
            $key = $setting['key'];
            $type = $setting['type'];
            $given = $input[$key] ?? null;

            if ('secret' === $type) {
                // Absent, empty, or the masked placeholder the panel shows: keep what is there.
                if (null === $given || '' === $given || self::MASK === $given) {
                    if (isset($existing[$key])) {
                        $stored[$key] = $existing[$key];
                    } elseif ($setting['required']) {
                        $errors[$key] = 'modules.errors.required';
                    }

                    continue;
                }

                $stored[$key] = Crypt::encryptString((string) $given);

                continue;
            }

            if (null === $given || '' === $given) {
                if (array_key_exists('default', $setting)) {
                    $stored[$key] = $setting['default'];
                } elseif ($setting['required']) {
                    $errors[$key] = 'modules.errors.required';
                }

                continue;
            }

            $value = match ($type) {
                'boolean' => filter_var($given, FILTER_VALIDATE_BOOL),
                'integer' => filter_var($given, FILTER_VALIDATE_INT),
                'url' => filter_var((string) $given, FILTER_VALIDATE_URL),
                'select' => in_array($given, $setting['options'], true) ? $given : false,
                default => mb_substr((string) $given, 0, 2000),
            };

            if (false === $value && 'boolean' !== $type) {
                $errors[$key] = 'modules.errors.invalid';

                continue;
            }

            // A URL an organiser types is fetched by our servers, so only the two schemes a
            // webhook or an endpoint is ever legitimately reached over.
            if ('url' === $type && ! in_array(parse_url((string) $value, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $errors[$key] = 'modules.errors.invalid';

                continue;
            }

            $stored[$key] = $value;
        }

        if ($errors) {
            throw ValidationException::withMessages(
                array_map(fn (string $key) => [__($key)], $errors)
            );
        }

        return $stored;
    }

    /**
     * The settings a module actually runs with: defaults filled in, secrets decrypted.
     *
     * This is the only place plaintext exists after it was written, and it exists here because a
     * module needs its API key to make a call.
     *
     * @return array<string, mixed>
     */
    public function resolve(ModuleManifest $manifest, ?string $tenantId): array
    {
        $stored = $tenantId ? $this->stored($manifest->key, $tenantId) : [];
        $resolved = [];

        foreach ($manifest->settings as $setting) {
            $key = $setting['key'];

            if (! array_key_exists($key, $stored)) {
                if (array_key_exists('default', $setting)) {
                    $resolved[$key] = $setting['default'];
                }

                continue;
            }

            if ('secret' === $setting['type']) {
                try {
                    $resolved[$key] = Crypt::decryptString((string) $stored[$key]);
                } catch (Throwable) {
                    // The app key was rotated, or the row was tampered with. Treating it as unset
                    // is the honest answer: the module will report itself unconfigured rather than
                    // authenticate with a string of noise.
                    continue;
                }

                continue;
            }

            $resolved[$key] = $stored[$key];
        }

        return $resolved;
    }

    /**
     * What the panel is allowed to see: every value except a secret, which is reported as set or
     * not set and never as itself.
     *
     * @return array<string, mixed>
     */
    public function present(ModuleManifest $manifest, ?string $tenantId): array
    {
        $stored = $tenantId ? $this->stored($manifest->key, $tenantId) : [];
        $shown = [];

        foreach ($manifest->settings as $setting) {
            $key = $setting['key'];

            $shown[$key] = 'secret' === $setting['type']
                ? (isset($stored[$key]) ? self::MASK : null)
                : ($stored[$key] ?? $setting['default'] ?? null);
        }

        return $shown;
    }

    /** @return array<string, mixed> */
    private function stored(string $moduleKey, string $tenantId): array
    {
        $row = TenantModule::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('module_key', $moduleKey)
            ->first();

        return (array) ($row?->settings ?? []);
    }

    /** What the panel shows where a secret is set, and what it sends back to mean "unchanged". */
    public const MASK = '••••••••';
}
