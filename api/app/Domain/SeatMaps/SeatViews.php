<?php

namespace App\Domain\SeatMaps;

use App\Domain\Sites\Themes;
use App\Models\SeatMap;
use App\Models\SeatMapVersion;
use App\Models\SeatView;
use App\Support\Tenancy\TenantContext;

/**
 * What a buyer would see from where they are about to sit.
 *
 * A seat plan answers "where" and the price answers "how much". The question somebody choosing
 * between the stalls and the balcony is actually asking — what does the stage look like from there
 * — had no answer at all, and it is the one that decides the sale.
 *
 * Keyed by the section's own key rather than by anything the geometry regenerates, so a picture
 * survives republishing the chart. And addresses go through the same check every other
 * organiser-supplied URL on this platform goes through: http(s) or nothing.
 */
class SeatViews
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * Every section of a chart, with the picture attached to it where there is one.
     *
     * The sections come from the map's *draft* where it has one, so an organiser can attach a
     * photograph to a section they have just drawn and not yet published — which is exactly when
     * they are thinking about it.
     *
     * @return list<array{section_key: string, name: string, url: ?string, caption: string}>
     */
    public function forMap(SeatMap $map): array
    {
        $version = $map->draftVersion() ?: SeatMapVersion::find($map->published_version_id);

        $stored = SeatView::where('seat_map_id', $map->id)->get()->keyBy('section_key');

        $out = [];

        foreach (self::sectionsIn($version) as $key => $name) {
            $one = $stored->get($key);

            $out[] = [
                'section_key' => $key,
                'name' => $name,
                'url' => $one?->url,
                'caption' => (string) ($one?->caption ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Replace the set, whole.
     *
     * A row with no address is a picture taken away rather than a row with an empty string in it —
     * "no picture" is the absence of one, and storing the absence would put an empty frame in a
     * buyer's way.
     *
     * @param  array<int, array{section_key?: string, url?: ?string, caption?: ?string}>  $rows
     */
    public function save(SeatMap $map, array $rows): array
    {
        $sections = self::sectionsIn(
            $map->draftVersion() ?: SeatMapVersion::find($map->published_version_id)
        );

        foreach ($rows as $row) {
            $key = trim((string) ($row['section_key'] ?? ''));
            $url = Themes::url($row['url'] ?? null);

            // A section this chart does not have is not a section. Silently ignored rather than
            // refused: a panel sending a stale list after a republish is a stale list, not an
            // attack, and losing the whole save over one dropped section would be worse.
            if ('' === $key || ! array_key_exists($key, $sections)) {
                continue;
            }

            if (! $url) {
                SeatView::where('seat_map_id', $map->id)->where('section_key', $key)->delete();

                continue;
            }

            SeatView::updateOrCreate(
                ['seat_map_id' => $map->id, 'section_key' => $key],
                [
                    'tenant_id' => $this->tenants->idOrFail(),
                    'url' => $url,
                    'caption' => mb_substr(trim((string) ($row['caption'] ?? '')), 0, 200),
                ],
            );
        }

        return $this->forMap($map->fresh());
    }

    /**
     * What the picker is booted with: section key to picture.
     *
     * Read against the *map*, not the version, because a photograph outlives a republish. An event
     * still selling against last month's chart shows this month's photographs, which is correct:
     * the room did not change, the drawing of it did.
     *
     * @return array<string, array{url: string, caption: string}>
     */
    public function forBoot(?SeatMapVersion $version): array
    {
        if (! $version) {
            return [];
        }

        $out = [];

        foreach (SeatView::where('seat_map_id', $version->seat_map_id)->get() as $view) {
            $out[$view->section_key] = [
                'url' => $view->url,
                'caption' => (string) $view->caption,
            ];
        }

        return $out;
    }

    /**
     * The sections a chart has, by key.
     *
     * Walked rather than queried: sections are geometry and live nowhere else, and a section inside
     * a section is legal, so this recurses.
     *
     * @return array<string, string>
     */
    public static function sectionsIn(?SeatMapVersion $version): array
    {
        if (! $version || ! is_array($version->geometry)) {
            return [];
        }

        $found = [];

        $walk = function (array $objects) use (&$walk, &$found): void {
            foreach ($objects as $object) {
                if (! is_array($object) || 'section' !== ($object['type'] ?? null)) {
                    continue;
                }

                $key = (string) ($object['key'] ?? '');

                if ('' !== $key) {
                    // The same name the picker paints on the block: what the designer typed in the
                    // labelling panel, falling back to the object's own label and then to its key.
                    $found[$key] = (string) (
                        $object['labeling']['label'] ?? $object['label'] ?? $key
                    );
                }

                if (is_array($object['objects'] ?? null)) {
                    $walk($object['objects']);
                }
            }
        };

        foreach ($version->geometry['floors'] ?? [] as $floor) {
            $walk($floor['objects'] ?? []);
        }

        return $found;
    }
}
