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
        'hero', 'heading', 'richText', 'image', 'slideshow', 'video', 'specs', 'terms',
        'buttons', 'buy', 'eventList', 'eventDetail', 'faq', 'venueMap', 'divider', 'html',
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
        'slideshow' => ['title'],
        'video' => ['title', 'caption'],
        'specs' => ['title'],
        'terms' => ['title', 'text'],
        'buy' => ['title', 'label', 'note'],
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
            'slideshow' => 'layers',
            'video' => 'play',
            'specs' => 'list',
            'terms' => 'file',
            'buy' => 'ticket',
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

    /**
     * Drop what somebody began and did not finish.
     *
     * The sanitiser keeps a half-written list row, and it has to: an editor's "add a picture" that
     * pushes a row the server deletes on the way past is a button which appears to do nothing, and
     * the row it drew is then bound to an object nobody is saving. So a draft may hold a slide with
     * no address and a question nobody answered.
     *
     * A published page may not. A blank slide on a live site is not a work in progress, it is a
     * hole — so this runs once, between the draft and the world, and it is the only place the
     * difference between those two lives.
     *
     * Rows are identified by the one field that makes them a row at all: a picture needs an
     * address, a fact needs a label, a question needs asking, a button needs both its words and
     * somewhere to go.
     */
    public static function tidy(array $blocks): array
    {
        // Non-arrays filtered first: every stored block came through `sanitiseAll` and is one, but
        // this runs on every read of a page and a row written by an older version of the panel is
        // not worth a type error.
        return array_values(array_map(function (array $block) {
            $keep = match ($block['type'] ?? '') {
                'slideshow' => fn (array $row) => '' !== ($row['url'] ?? ''),
                'specs' => fn (array $row) => '' !== ($row['label'] ?? ''),
                'faq' => fn (array $row) => '' !== ($row['question'] ?? ''),
                'buttons' => fn (array $row) => '' !== ($row['label'] ?? '') && '' !== ($row['href'] ?? ''),
                default => null,
            };

            if (null === $keep || ! is_array($block['items'] ?? null)) {
                return $block;
            }

            $block['items'] = array_values(array_filter($block['items'], $keep));

            return $block;
        }, array_filter($blocks, 'is_array')));
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
            /*
             * Several pictures where one will not do.
             *
             * A venue's own photographs are most of what persuades somebody to come, and a page
             * that can hold one of them is a page with a picture on it rather than a hall somebody
             * can see themselves in.
             */
            'slideshow' => $out + [
                'title' => self::text($block['title'] ?? '', 120),
                'items' => self::slides($block['items'] ?? []),
                'height' => self::choice($block['height'] ?? 'tall', ['short', 'tall'], 'tall'),
                // Off unless asked for. A carousel that moves on its own takes the page away from
                // somebody reading it, and it is the first thing an accessibility audit asks about.
                'autoplay' => (bool) ($block['autoplay'] ?? false),
            ],
            /*
             * A film, resolved here rather than at render time.
             *
             * The provider and the id are worked out on the way in, so rendering has nothing to
             * decide and no URL from an organiser ever reaches an `iframe` src. An address this
             * does not recognise leaves the provider null and renders nothing, the same way an
             * image block with no picture renders nothing.
             */
            'video' => $out + self::video($block['url'] ?? '') + [
                'title' => self::text($block['title'] ?? '', 160),
                'caption' => self::text($block['caption'] ?? '', 300),
                'poster' => Themes::url($block['poster'] ?? null),
            ],
            /*
             * The facts: doors, running time, interval, age limit, what the seat is like.
             *
             * A list of label and value rather than prose, because that is how somebody reads it —
             * they are looking for one of the rows, not for the paragraph it is buried in.
             */
            'specs' => $out + [
                'title' => self::text($block['title'] ?? '', 120),
                'items' => self::specs($block['items'] ?? []),
            ],
            /*
             * The conditions of sale, which every venue has and nobody reads twice.
             *
             * Foldable, and folded by default where an organiser says so: terms that push the
             * booking button off the screen are terms that cost tickets, and terms nobody can find
             * are not terms at all.
             */
            'terms' => $out + [
                'title' => self::text($block['title'] ?? '', 120),
                'text' => self::text($block['text'] ?? '', 8000),
                'collapsed' => (bool) ($block['collapsed'] ?? true),
            ],
            'buttons' => $out + [
                'items' => self::buttons($block['items'] ?? []),
            ],
            /*
             * Buy this one, from anywhere on the site.
             *
             * Empty `event_public_id` means the night this page is for, exactly as the detail block
             * reads it — so one page serves every event and a page about one night can still carry
             * a button for it.
             */
            'buy' => $out + [
                'event_public_id' => self::text($block['event_public_id'] ?? '', 60),
                'title' => self::text($block['title'] ?? '', 120),
                'label' => self::text($block['label'] ?? '', 60),
                'note' => self::text($block['note'] ?? '', 300),
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

            $clean[] = [
                'label' => self::text($item['label'] ?? '', 60),
                // Empty rather than absent where it is not a link: the row is kept so the editor
                // can finish it, and `tidy()` is what stops an unfinished one being published.
                'href' => self::href($item['href'] ?? '') ?? '',
                'style' => self::choice($item['style'] ?? 'primary', ['primary', 'secondary'], 'primary'),
            ];
        }

        return $clean;
    }

    /**
     * The pictures in a slideshow.
     *
     * Twelve at most: a carousel longer than that is an album, and nobody reaches the end of one.
     * A slide whose address is not a picture address keeps the rest of the row and an empty one,
     * so that pasting something unusable loses the address rather than the caption beside it.
     */
    private static function slides(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $clean = [];

        foreach (array_slice($items, 0, 12) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $clean[] = [
                'url' => Themes::url($item['url'] ?? null) ?? '',
                'alt' => self::text($item['alt'] ?? '', 200),
                'caption' => self::text($item['caption'] ?? '', 200),
                // A slide may lead somewhere — usually the night it is a photograph of.
                'href' => self::href($item['href'] ?? '') ?? '',
            ];
        }

        return $clean;
    }

    /** Label and value, twenty at most: past that it is a page rather than a panel of facts. */
    private static function specs(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $clean = [];

        foreach (array_slice($items, 0, 20) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $clean[] = [
                'label' => self::text($item['label'] ?? '', 80),
                'value' => self::text($item['value'] ?? '', 300),
            ];
        }

        return $clean;
    }

    /**
     * Which service a film is on, and its id there.
     *
     * Resolved on the way in and stored as a provider and a key, so that nothing an organiser typed
     * is ever interpolated into an `iframe` src at render time. The id patterns are deliberately
     * narrow — letters, digits, dash and underscore for YouTube, digits for Vimeo — because that is
     * the whole of the defence: an address that does not match is not a video, it is a string.
     *
     * A direct file is kept as a URL, because there is nothing to look up: the browser plays it
     * with its own controls and no third party is involved at all.
     *
     * @return array{provider: ?string, key: ?string, url: string}
     */
    public static function video(mixed $value): array
    {
        $nothing = ['provider' => null, 'key' => null, 'url' => ''];
        $url = trim((string) (is_scalar($value) ? $value : ''));

        if ('' === $url || ! Themes::url($url)) {
            return $nothing;
        }

        $parts = parse_url($url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        parse_str((string) ($parts['query'] ?? ''), $query);

        $host = str_starts_with($host, 'www.') ? mb_substr($host, 4) : $host;

        if (in_array($host, ['youtube.com', 'm.youtube.com', 'youtube-nocookie.com'], true)) {
            $id = (string) ($query['v'] ?? '');

            // Also the /embed/ and /shorts/ shapes, which is what somebody copying an address bar
            // actually has in their hand half the time.
            if ('' === $id && preg_match('#^/(?:embed|shorts|v)/([A-Za-z0-9_-]+)#', $path, $found)) {
                $id = $found[1];
            }

            return self::youTube($id, $url);
        }

        if ('youtu.be' === $host) {
            return self::youTube(ltrim($path, '/'), $url);
        }

        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
            return preg_match('#(\d{6,12})#', $path, $found)
                ? ['provider' => 'vimeo', 'key' => $found[1], 'url' => $url]
                : $nothing;
        }

        // A file this browser can play on its own, with nobody else watching.
        if (preg_match('/\.(mp4|webm|ogg|ogv)$/i', $path)) {
            return ['provider' => 'file', 'key' => $url, 'url' => $url];
        }

        return $nothing;
    }

    /** @return array{provider: ?string, key: ?string, url: string} */
    private static function youTube(string $id, string $url): array
    {
        return preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)
            ? ['provider' => 'youtube', 'key' => $id, 'url' => $url]
            : ['provider' => null, 'key' => null, 'url' => ''];
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

            $clean[] = [
                'question' => self::text($item['question'] ?? '', 300),
                'answer' => self::text($item['answer'] ?? '', 4000),
            ];
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
