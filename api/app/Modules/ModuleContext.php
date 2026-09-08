<?php

namespace App\Modules;

/**
 * Everything a module is given, and nothing else (ADR-0004 §2).
 *
 * A module gets its own settings, the tenant it is running for, and a logger. It does **not** get
 * the container, a database connection, or the inventory models — those guarantees are the product,
 * and code that could reach around them would turn them into opinions.
 *
 * This is a value object rather than a service locator on purpose. A module holding a container
 * could resolve anything, and "a module may do X" would stop being a question the manifest answers.
 */
final class ModuleContext
{
    public function __construct(
        public readonly ModuleManifest $manifest,
        public readonly ?string $tenantId,
        private readonly array $settings,
    ) {}

    /**
     * A setting the organiser gave this module for this tenant.
     *
     * Secrets are decrypted here and nowhere else — a module needs its API key to make a call, and
     * that is the only place the plaintext exists after it was written.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->settings[$key]) && '' !== $this->settings[$key];
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->settings;
    }

    /** For a module reporting its own trouble. Prefixed, so a log line names its author. */
    public function log(string $level, string $message, array $context = []): void
    {
        logger()->log($level, '['.$this->manifest->key.'] '.$message, $context + [
            'module' => $this->manifest->key,
            'tenant' => $this->tenantId,
        ]);
    }
}
