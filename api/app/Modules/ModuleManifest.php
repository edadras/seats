<?php

namespace App\Modules;

use InvalidArgumentException;

/**
 * A module's `module.json`, read once and checked.
 *
 * The manifest is the whole of what a module declares about itself: what it extends, what it needs
 * configured, and what to instantiate. Everything outside this list is not a thing a module can do
 * (ADR-0004 §1) — an open-ended hook system is an open-ended security review, and what is on the
 * other side of this boundary is somebody's ticket revenue.
 */
final class ModuleManifest
{
    /**
     * The extension points that exist. Adding one is a core change and an amendment to ADR-0004,
     * on purpose.
     */
    public const POINTS = [
        'payments',   // a way to take money
        'messaging',  // a way to reach a buyer
        'reports',    // a queryable dataset for the report builder
        'blocks',     // a block an organiser can put on a page
        'themes',     // site themes
        'panel',      // a nav entry and a screen
        'events',     // reactions to domain events
    ];

    /** Setting types a module may declare. Anything else is rejected when the manifest is read. */
    public const SETTING_TYPES = ['string', 'secret', 'boolean', 'integer', 'url', 'select'];

    /**
     * @param  list<string>  $extends
     * @param  list<array{key:string,type:string,label_key:string,required:bool,options?:list<string>,default?:mixed}>  $settings
     */
    private function __construct(
        public readonly string $key,
        public readonly string $vendor,
        public readonly string $name,
        public readonly string $version,
        public readonly string $provider,
        public readonly array $extends,
        public readonly array $settings,
        public readonly string $nameKey,
        public readonly string $descriptionKey,
        public readonly string $path,
        public readonly bool $firstParty,
        public readonly bool $autoEnable,
    ) {}

    public static function fromArray(array $data, string $path): self
    {
        $key = (string) ($data['key'] ?? '');

        // `vendor/name`, both lowercase and hyphenated. The key ends up in a URL, in a database
        // column and in a translation key, and a manifest that could put anything there is a
        // manifest that can reach into all three.
        if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\/[a-z0-9]+(?:-[a-z0-9]+)*$/', $key)) {
            throw new InvalidArgumentException("Module at {$path} has an invalid key [{$key}]. Expected vendor/name.");
        }

        $provider = (string) ($data['provider'] ?? '');

        if ('' === $provider || ! class_exists($provider)) {
            throw new InvalidArgumentException("Module [{$key}] names a provider that does not exist: [{$provider}].");
        }

        if (! is_subclass_of($provider, ModuleProvider::class)) {
            throw new InvalidArgumentException("Module [{$key}] provider must extend ".ModuleProvider::class.'.');
        }

        $extends = array_values(array_unique(array_filter(
            (array) ($data['extends'] ?? []),
            fn ($point) => is_string($point) && in_array($point, self::POINTS, true)
        )));

        if (! $extends) {
            throw new InvalidArgumentException("Module [{$key}] extends nothing the platform offers.");
        }

        [$vendor] = explode('/', $key);

        $settings = self::settings($key, (array) ($data['settings'] ?? []));
        $requiresSettings = (bool) array_filter($settings, fn (array $setting) => $setting['required']);

        return new self(
            key: $key,
            vendor: $vendor,
            name: (string) ($data['name'] ?? $key),
            version: (string) ($data['version'] ?? '0.0.0'),
            provider: $provider,
            extends: $extends,
            settings: $settings,
            // First-party modules keep their strings in the platform catalogue, so the same CI check
            // that guards the rest of the six languages guards them too.
            nameKey: (string) ($data['name_key'] ?? 'modules.'.str_replace(['/', '-'], ['.', '_'], $key).'.name'),
            descriptionKey: (string) ($data['description_key'] ?? 'modules.'.str_replace(['/', '-'], ['.', '_'], $key).'.description'),
            path: $path,
            firstParty: 'seatmap' === $vendor,
            /*
             * On for a new organiser unless they turn it off.
             *
             * Only meaningful for a module that needs no configuration — one that does would be
             * "enabled and broken", which is worse than off. Enforced below rather than trusted.
             */
            autoEnable: (bool) ($data['auto_enable'] ?? false) && ! $requiresSettings,
        );
    }

    /** @return list<array<string, mixed>> */
    private static function settings(string $key, array $declared): array
    {
        $settings = [];

        foreach ($declared as $index => $setting) {
            if (! is_array($setting)) {
                continue;
            }

            $name = (string) ($setting['key'] ?? '');
            $type = (string) ($setting['type'] ?? 'string');

            if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                throw new InvalidArgumentException("Module [{$key}] setting #{$index} has an invalid key [{$name}].");
            }

            if (! in_array($type, self::SETTING_TYPES, true)) {
                throw new InvalidArgumentException("Module [{$key}] setting [{$name}] has an unknown type [{$type}].");
            }

            $entry = [
                'key' => $name,
                'type' => $type,
                'required' => (bool) ($setting['required'] ?? false),
                'label_key' => (string) ($setting['label_key'] ?? 'modules.'.str_replace(['/', '-'], ['.', '_'], $key).'.settings.'.$name),
                'hint_key' => isset($setting['hint_key']) ? (string) $setting['hint_key'] : null,
            ];

            if ('select' === $type) {
                $entry['options'] = array_values(array_filter(
                    (array) ($setting['options'] ?? []),
                    'is_string'
                ));

                if (! $entry['options']) {
                    throw new InvalidArgumentException("Module [{$key}] setting [{$name}] is a select with no options.");
                }
            }

            // A secret has no default. A default secret is a shared secret.
            if (array_key_exists('default', $setting) && 'secret' !== $type) {
                $entry['default'] = $setting['default'];
            }

            $settings[] = $entry;
        }

        return $settings;
    }

    public function extendsPoint(string $point): bool
    {
        return in_array($point, $this->extends, true);
    }

    /** @return list<string> */
    public function secretKeys(): array
    {
        return array_values(array_map(
            fn (array $setting) => $setting['key'],
            array_filter($this->settings, fn (array $setting) => 'secret' === $setting['type'])
        ));
    }
}
