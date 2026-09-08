<?php

namespace Tests\Feature;

use App\Modules\ModuleProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * The boundary a module may not cross (ADR-0004 §2), checked rather than asked for.
 *
 * The guarantees in ADR-0002 — that a seat is sold exactly once, that state is derived and never
 * stored — are the product. Code that could reach around them would turn them into opinions. So
 * this test reads every module shipped in this repository and fails if one so much as mentions the
 * inventory.
 *
 * It is a lint, not a sandbox, and it is honest about that: it catches the mistake, not the
 * attacker. What stops an attacker is that nobody can put code here without a deploy, which is the
 * whole reason there is no upload-and-run path.
 */
class ModuleBoundaryTest extends TestCase
{
    /**
     * Names that only appear in code reaching for seating state. `Ticket` is on the list because a
     * module that wanted to mint one has misunderstood what a module is for.
     */
    private const FORBIDDEN = [
        'App\\Models\\Seat',
        'App\\Models\\Hold',
        'App\\Models\\HoldItem',
        'App\\Models\\Allocation',
        'App\\Models\\Ticket',
        'App\\Models\\SeatMapVersion',
        'App\\Models\\Checkin',
        'Illuminate\\Support\\Facades\\DB',
        'Illuminate\\Database\\Eloquent\\Model',
    ];

    #[Test]
    public function no_shipped_module_reaches_for_the_inventory(): void
    {
        $files = $this->moduleSources();

        $this->assertNotEmpty($files, 'No modules found — this test would pass vacuously.');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::FORBIDDEN as $name) {
                $this->assertStringNotContainsString(
                    $name,
                    $source,
                    sprintf(
                        "%s mentions %s.\n\nA module reacts to events and calls published services; ".
                        "it does not read or write seating state (ADR-0004 §2). If it genuinely needs ".
                        "something it cannot get, that is a new extension point and an ADR amendment.",
                        str_replace(base_path('../'), '', $file),
                        $name
                    )
                );
            }
        }
    }

    #[Test]
    public function every_shipped_module_extends_the_provider_and_nothing_more(): void
    {
        foreach ($this->manifests() as $manifest) {
            $provider = $manifest['provider'] ?? '';

            $this->assertTrue(class_exists($provider), "Module {$manifest['key']} names a provider that does not exist.");
            $this->assertTrue(
                is_subclass_of($provider, ModuleProvider::class),
                "Module {$manifest['key']} must extend ".ModuleProvider::class.'.'
            );

            $reflection = new ReflectionClass($provider);

            // A provider may only answer the questions the base class asks. A public method that is
            // not one of those is a surface the platform never calls and nobody reviewed.
            $allowed = array_map(
                fn (\ReflectionMethod $method) => $method->getName(),
                (new ReflectionClass(ModuleProvider::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
            );

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $provider) {
                    continue;
                }

                $this->assertContains(
                    $method->getName(),
                    $allowed,
                    sprintf('%s::%s() is not an extension point.', $provider, $method->getName())
                );
            }
        }
    }

    #[Test]
    public function every_shipped_module_declares_its_strings_in_the_platform_catalogue(): void
    {
        foreach ($this->manifests() as $manifest) {
            $key = str_replace(['/', '-'], ['.', '_'], $manifest['key']);

            // First-party modules keep their strings where the six-language CI check can see them.
            // A name that renders as its own key is a module nobody translated.
            foreach (['name', 'description'] as $field) {
                $translationKey = 'modules.'.$key.'.'.$field;

                $this->assertNotSame(
                    $translationKey,
                    __($translationKey),
                    "Module {$manifest['key']} has no {$field} in api/lang/en/modules.php."
                );
            }
        }
    }

    /** @return list<string> */
    private function moduleSources(): array
    {
        $root = config('seatmap.modules.path');

        return is_dir($root)
            ? array_values(array_filter(glob($root.'/*/*/*.php') ?: [], 'is_file'))
            : [];
    }

    /** @return list<array<string, mixed>> */
    private function manifests(): array
    {
        $root = config('seatmap.modules.path');

        if (! is_dir($root)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $file) => json_decode((string) file_get_contents($file), true),
            glob($root.'/*/*/module.json') ?: []
        ), 'is_array'));
    }
}
