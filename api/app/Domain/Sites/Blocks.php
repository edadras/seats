<?php

namespace App\Domain\Sites;

use Illuminate\Support\Str;

/**
 * The block vocabulary a page is built from.
 *
 * Every block is `{type, …}` and every field is typed and bounded here, on the way *in*. Rendering
 * then has nothing to decide: it matches on type and escapes everything. An unknown type is dropped
 * rather than rendered, so a page written by a newer version of the panel degrades to its other
 * blocks instead of erroring.
 *
 * `html` is the one block that emits markup an organiser typed. It is sanitised to a small tag set
 * here, and gated by plan, because organiser-supplied markup on a domain we serve is a stored-XSS
 * surface that crosses tenants.
 */
class Blocks
{
    public const TYPES = [
        'hero', 'heading', 'richText', 'image', 'buttons', 'eventList', 'eventDetail',
        'faq', 'venueMap', 'divider', 'html',
    ];

    /**
     * Which fields of each block are prose, and therefore worth another language.
     *
     * The map is here rather than in the panel because the panel would then hold a second opinion
     * about the shape of a block, and the two would disagree the first time one changed.
     *
     * `buttons` and the `faq` items are deliberately absent: they are lists, and an overlay keyed
     * by field cannot address a list item without inventing a shape for it. A venue that needs its
     * FAQ in two languages needs two FAQ blocks, which is honest about what is happening.
     */
    public const WORDS = [
        'hero' => ['title', 'subtitle'],
        'heading' => ['text'],
        'richText' => ['text'],
        'image' => ['alt', 'caption'],
        'eventList' => ['title'],
        'faq' => ['title'],
        'venueMap' => ['title', 'address', 'directions'],
        'html' => ['html'],
    ];

    /** Tags the `html` block keeps. Everything else, including every attribute, is stripped. */
    private const ALLOWED_TAGS = '<p><br><strong><em><b><i><u><ul><ol><li><h2><h3><h4><blockquote><a><table><thead><tbody><tr><th><td>';

    /** The block picker in the panel, named in whatever language the panel is being read in. */
    public static function describe(): array
    {
        $icons = [
            'hero' => 'image',
            'heading' => 'text',
            'richText' => 'text',
            'image' => 'image',
            'buttons' => 'cursor',
            'eventList' => 'calendar',
            'eventDetail' => 'seat',
            'faq' => 'help',
            'venueMap' => 'map',
            'divider' => 'minus',
            'html' => 'shape',
        ];

        return array_map(
            fn (string $type) => [
                'type' => $type,
                'name' => __('site.blocks.'.$type),
                'icon' => $icons[$type],
                // The fields worth writing in another language, so the translation screen builds
                // itself from the same map the sanitiser uses rather than from a copy of it.
                'words' => self::WORDS[$type] ?? [],
            ],
            self::TYPES
        );
    }

    /**
     * Normalise a list of blocks from the panel.
     *
     * Returns only well-formed blocks of known types. This is the boundary: nothing downstream
     * re-validates, so anything that gets past here is treated as safe to render.
     */
    public static function sanitiseAll(array $blocks, bool $allowHtml = false): array
    {
        $limit = (int) config('seatmap.sites.limits.max_blocks_per_page', 100);
        $clean = [];

        foreach (array_slice(array_values($blocks), 0, $limit) as $block) {
            if (! is_array($block)) {
                continue;
            }

            $one = self::sanitise($block, $allowHtml);

            if ($one) {
                $clean[] = $one;
            }
        }

        return $clean;
    }

    private static function sanitise(array $block, bool $allowHtml): ?array
    {
        $type = is_string($block['type'] ?? null) ? $block['type'] : '';

        if (! in_array($type, self::TYPES, true)) {
            return null;
        }

        // A stable id per block, so the editor can reorder without React-style key churn and so an
        // anchor link to a section survives an edit elsewhere on the page.
        $id = is_string($block['id'] ?? null) && '' !== $block['id']
            ? Str::limit(preg_replace('/[^A-Za-z0-9_-]/', '', $block['id']), 40, '')
            : 'b'.Str::lower(Str::random(10));

        $out = ['id' => $id, 'type' => $type];

        return match ($type) {
            'hero' => $out + [
                'title' => self::text($block['title'] ?? '', 160),
                'subtitle' => self::text($block['subtitle'] ?? '', 400),
                'url' => Themes::url($block['url'] ?? null),
                'align' => self::choice($block['align'] ?? 'start', ['start', 'center'], 'start'),
                'height' => self::choice($block['height'] ?? 'tall', ['short', 'tall', 'full'], 'tall'),
            ],
            'heading' => $out + [
                'text' => self::text($block['text'] ?? '', 200),
                'level' => in_array($block['level'] ?? 2, [2, 3, 4], true) ? (int) $block['level'] : 2,
                'align' => self::choice($block['align'] ?? 'start', ['start', 'center'], 'start'),
            ],
            'richText' => $out + [
                'text' => self::text($block['text'] ?? '', 8000),
            ],
            'image' => $out + [
                'url' => Themes::url($block['url'] ?? null),
                'alt' => self::text($block['alt'] ?? '', 200),
                'caption' => self::text($block['caption'] ?? '', 300),
                'width' => self::choice($block['width'] ?? 'content', ['content', 'wide', 'full'], 'content'),
            ],
            'buttons' => $out + [
                'items' => self::buttons($block['items'] ?? []),
            ],
            'eventList' => $out + [
                'title' => self::text($block['title'] ?? '', 120),
                'limit' => max(1, min(50, (int) ($block['limit'] ?? 12))),
                'layout' => self::choice($block['layout'] ?? 'cards', ['cards', 'list', 'spotlight'], 'cards'),
                // On unless somebody turns it off, including on the pages that existed before this
                // was an option: a programme of thirty dates that cannot be searched is a list
                // people scroll past.
                'search' => (bool) ($block['search'] ?? true),
            ],
            'eventDetail' => $out + [
                // Empty means "the event this page is for", which is how one page serves every event.
                'event_public_id' => self::text($block['event_public_id'] ?? '', 60),
            ],
            'faq' => $out + [
                'title' => self::text($block['title'] ?? '', 120),
                'items' => self::faqItems($block['items'] ?? []),
            ],
            'venueMap' => $out + [
                'title' => self::text($block['title'] ?? '', 120),
                'address' => self::text($block['address'] ?? '', 400),
                'directions' => self::text($block['directions'] ?? '', 2000),
            ],
            'divider' => $out,
            'html' => $allowHtml
                ? $out + ['html' => self::html($block['html'] ?? '')]
                : null,
            default => null,
        };
    }

    private static function text(mixed $value, int $max): string
    {
        return Str::limit(trim((string) (is_scalar($value) ? $value : '')), $max, '');
    }

    private static function choice(mixed $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? (string) $value : $fallback;
    }

    private static function buttons(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $clean = [];

        foreach (array_slice($items, 0, 4) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $label = self::text($item['label'] ?? '', 60);
            $href = self::href($item['href'] ?? '');

            if ('' === $label || null === $href) {
                continue;
            }

            $clean[] = [
                'label' => $label,
                'href' => $href,
                'style' => self::choice($item['style'] ?? 'primary', ['primary', 'secondary'], 'primary'),
            ];
        }

        return $clean;
    }

    private static function faqItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $clean = [];

        foreach (array_slice($items, 0, 30) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = self::text($item['question'] ?? '', 300);

            if ('' === $question) {
                continue;
            }

            $clean[] = ['question' => $question, 'answer' => self::text($item['answer'] ?? '', 4000)];
        }

        return $clean;
    }

    /** A link may be internal (starts with /) or absolute http(s). Nothing else is a link. */
    public static function href(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ('' === $value) {
            return null;
        }

        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return $value;
        }

        return Themes::url($value);
    }

    private static function html(mixed $value): string
    {
        $html = strip_tags((string) (is_scalar($value) ? $value : ''), self::ALLOWED_TAGS);

        // Attributes go entirely: keeping `href` would mean auditing every scheme, keeping `style`
        // would mean parsing CSS, and keeping `on*` is how this becomes someone else's incident.
        $html = preg_replace('/<([a-zA-Z0-9]+)(\s[^>]*)?>/', '<$1>', $html);

        return Str::limit($html, 20000, '');
    }
}
