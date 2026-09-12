<?php

namespace App\Console\Commands;

use App\Domain\Sites\WebApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Everything about this installation that is wrong in a way nothing else will tell you.
 *
 * A deployment checklist in a document is a deployment checklist somebody reads once. The failures
 * worth catching here are all of the same shape — the application starts, the panel loads, a seat
 * can be held, and something that matters is quietly not happening:
 *
 *   - mail goes to a log file, so nobody's ticket ever arrives;
 *   - no proxy is trusted, so every buyer shares one rate-limit bucket and every audit row records
 *     the load balancer;
 *   - the queue is `sync`, so holds are never swept and webhooks never leave;
 *   - a webhook address is not checked, so the panel is a way to make this server fetch its own
 *     metadata endpoint;
 *   - `intl` is missing, so every price throws on the page that shows it.
 *
 * None of those is visible on a smoke test of the home page, and all of them are visible here.
 *
 * Three levels, and the distinction is deliberate. A **failure** means something will not work, and
 * the exit status says so, so a deploy script can stop. A **warning** means a decision has not been
 * made and a default has been taken — which is usually fine on a staging box and usually wrong on a
 * real one. A **note** is a fact worth reading once.
 *
 * Nothing here writes anything, so it is safe to run against a live installation, and it should be:
 * the configuration it reads can drift after a deploy as easily as during one.
 */
class Preflight extends Command
{
    protected $signature = 'seatmap:preflight {--strict : Treat warnings as failures}';

    protected $description = 'Check this installation is fit to take money';

    /** @var list<array{level:string, title:string, detail:string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>Is this installation fit to take money?</>');

        $this->section('The application');
        $this->application();

        $this->section('PHP');
        $this->php();

        $this->section('Stores');
        $this->stores();

        $this->section('Work that happens without a request');
        $this->background();

        $this->section('Who the client is');
        $this->proxies();

        $this->section('Reaching people');
        $this->mail();

        $this->section('Hosts and addresses');
        $this->hosts();

        $this->section('What has to be on disk');
        $this->disk();

        $this->section('Where the pictures go');
        $this->media();

        return $this->verdict();
    }

    /* --------------------------------------------------------------------------- the checks */

    private function application(): void
    {
        $production = app()->isProduction();

        $this->check(
            '' !== (string) config('app.key'),
            'APP_KEY is set',
            'APP_KEY is empty. Every API client secret and webhook signing secret is encrypted with '
            .'it. Run `php artisan key:generate` before anything else, and back the value up '
            .'separately from the database.',
        );

        $this->check(
            ! config('app.debug') || ! $production,
            config('app.debug') ? 'Debug mode is on, which is allowed outside production' : 'Debug mode is off',
            'APP_DEBUG is true with APP_ENV=production. A stack trace on a failed checkout shows a '
            .'stranger the database structure and the contents of the request.',
        );

        $this->warnUnless(
            $production,
            $production ? 'APP_ENV is production' : 'APP_ENV is '.config('app.env'),
            // Titles on the warning path say what is, not what should be: a yellow line reading
            // "APP_ENV is production" above a detail saying it is not is a report nobody believes.
            'APP_ENV is '.config('app.env').'. Set it to `production` on a machine taking real '
            .'bookings: several guards read it rather than reading a flag of their own.',
        );

        $url = (string) config('app.url');

        $this->warnUnless(
            str_starts_with($url, 'https://') && ! str_contains($url, 'localhost'),
            str_starts_with($url, 'https://') && ! str_contains($url, 'localhost')
                ? 'APP_URL is an https address'
                : 'APP_URL is not a public https address',
            'APP_URL is '.($url ?: 'empty').'. It is what a Google sign-in redirect and an SSO '
            .'return address are built from, and both have to match what is registered elsewhere.',
        );

        $this->check(
            '' !== (string) config('seatmap.signing_key', ''),
            'Price snapshots are signed',
            'SEATMAP_SIGNING_KEY is empty. It signs the price a storefront was quoted. Run '
            .'`php artisan seatmap:generate-signing-key` and set it.',
        );
    }

    private function php(): void
    {
        // Three with no alternative. Every money figure on every page goes through `intl`; the
        // site's home-screen tile and the QR on a ticket are drawn by `gd`; Postgres is not
        // negotiable, for the partial unique indexes that make double-selling impossible.
        foreach (['intl', 'gd', 'pdo_pgsql'] as $extension) {
            $this->check(
                extension_loaded($extension),
                'The '.$extension.' extension is installed',
                'php-'.$extension.' is missing. It has no fallback here: '.match ($extension) {
                    'intl' => 'every price and date is formatted with it, so every page that shows one fails.',
                    'gd' => 'the ticket QR code and a site\'s app icon are drawn with it.',
                    default => 'this platform requires PostgreSQL.',
                },
            );
        }

        $this->warnUnless(
            function_exists('imagettftext') && is_readable(resource_path('fonts/Vazirmatn-Bold.ttf')),
            'Tiles can be lettered',
            'FreeType support or the bundled typeface is missing, so a site\'s home-screen icon is '
            .'drawn as plain colour with no initials on it. Nothing else is affected.',
        );

        $this->note(
            'Serving PHP '.PHP_VERSION.'. This application requires 8.3 or newer.',
        );
    }

    private function stores(): void
    {
        try {
            $version = (string) DB::selectOne('SHOW server_version')->server_version;
            $major = (int) $version;

            $this->check(
                'pgsql' === DB::connection()->getDriverName(),
                'The database is PostgreSQL '.$version,
                'The database driver is '.DB::connection()->getDriverName().'. Seat exclusivity is '
                .'enforced by partial unique indexes and `SELECT … FOR UPDATE`; on another engine '
                .'this system is not correct, only usually correct.',
            );

            $this->check(
                $major >= 14,
                'PostgreSQL is new enough',
                'PostgreSQL '.$version.' is older than 14.',
            );
        } catch (Throwable $e) {
            $this->check(false, 'The database answers', 'Cannot reach the database: '.$e->getMessage());
        }

        $this->indexes();

        try {
            Redis::connection()->ping();
            $this->pass('Redis answers');
        } catch (Throwable $e) {
            $this->check(
                false,
                'Redis answers',
                'Cannot reach Redis: '.$e->getMessage().'. It backs the HMAC nonce store, so every '
                .'signed request from a storefront will be refused with 503 replay_check_unavailable '
                .'— replay protection fails closed on purpose.',
            );
        }

        $this->warnUnless(
            ! app()->isProduction() || 'redis' === config('cache.default'),
            'The cache store is '.config('cache.default'),
            // Same string either way: naming the store *is* the finding.
            'The cache store is '.config('cache.default').'. Site resolution, module state and the '
            .'waiting room all cache; on more than one web server anything but a shared store means '
            .'they disagree with each other.',
        );
    }

    /**
     * The indexes that are the whole of the no-double-selling guarantee.
     *
     * Checked here and not only in CI because a migration that was never run on this machine, or an
     * index dropped by hand during an incident, leaves a system that passes every functional test
     * and oversells under load — which is the one failure this platform exists to prevent.
     */
    private function indexes(): void
    {
        $wanted = [
            'allocations_one_active_per_seat',
            'allocations_unique_per_external_order_seat',
            'hold_items_one_active_per_seat',
            'hold_items_capacity_active',
            'allocations_capacity_active',
        ];

        $constraints = ['hold_items_seat_xor_capacity', 'allocations_seat_xor_capacity'];

        try {
            $present = DB::table('pg_indexes')->whereIn('indexname', $wanted)->pluck('indexname')->all();
            $held = DB::table('pg_constraint')->whereIn('conname', $constraints)->pluck('conname')->all();

            $missing = array_merge(
                array_diff($wanted, $present),
                array_diff($constraints, $held),
            );

            $this->check(
                [] === $missing,
                'The integrity indexes are in place',
                'Missing: '.implode(', ', $missing).'. These are what make it impossible to sell one '
                .'seat twice. Run `php artisan migrate --force`; if they are still absent after '
                .'that, do not open sales.',
            );
        } catch (Throwable $e) {
            $this->check(false, 'The integrity indexes are in place', $e->getMessage());
        }

        try {
            $pending = collect(app('migrator')->getMigrationFiles(app('migrator')->paths() ?: [database_path('migrations')]))
                ->keys()
                ->diff(app('migrator')->getRepository()->getRan())
                ->count();

            $this->check($pending < 1, 'Every migration has run', $pending.' migration(s) have not run.');
        } catch (Throwable $e) {
            $this->note('Could not count pending migrations: '.$e->getMessage());
        }
    }

    private function background(): void
    {
        $queue = (string) config('queue.default');

        $this->check(
            'sync' !== $queue,
            'The queue is '.$queue,
            'The queue connection is `sync`, so every job runs inside the request that made it. '
            .'Ticket emails would be sent while a buyer waits, and a failure would fail their '
            .'checkout. Set QUEUE_CONNECTION=redis and run `php artisan queue:work`.',
        );

        $this->note(
            'A worker and the scheduler are both required and neither can be checked from here:'
            .PHP_EOL.'      php artisan queue:work --queue=default --tries=3'
            .PHP_EOL.'      * * * * * cd '.base_path().' && php artisan schedule:run >/dev/null 2>&1'
            .PHP_EOL.'    Without the worker, availability goes stale and webhooks stop. Without the'
            .PHP_EOL.'    scheduler, expired holds are never swept, so seats nobody bought stay sold.',
        );
    }

    private function proxies(): void
    {
        $proxies = config('trustedproxy.proxies');

        if ($proxies) {
            $this->pass('Trusted proxies: '.(is_array($proxies) ? implode(', ', $proxies) : $proxies));

            $this->warnUnless(
                '*' !== $proxies && '**' !== $proxies,
                'Trusted proxies are named rather than wildcarded',
                'Every proxy is trusted (`*`). If anything can reach this server without going '
                .'through the proxy, it can put whatever it likes in X-Forwarded-For and walk past '
                .'every per-IP limit from one machine. Name the proxy\'s address instead.',
            );

            return;
        }

        $this->warn_(
            'No proxy is trusted',
            'SEATMAP_TRUSTED_PROXIES is empty. Correct if this server is exposed directly. If it '
            .'sits behind nginx, Caddy, a load balancer or a CDN, then `$request->ip()` is the '
            .'proxy\'s address on every request — so signing in, signing up, the per-person seat '
            .'limit and the bot defence all share one bucket between every buyer, and every audit '
            .'row records the proxy. Set it to the proxy\'s address (127.0.0.1,::1 for nginx on '
            .'this host).',
        );
    }

    private function mail(): void
    {
        $mailer = (string) config('mail.default');

        $this->check(
            'log' !== $mailer && 'array' !== $mailer,
            'Mail is sent by '.$mailer,
            'MAIL_MAILER is `'.$mailer.'`, which writes to '.('array' === $mailer ? 'memory' : 'the log file')
            .' instead of sending. Nobody receives a ticket, an invitation or a password reset, and '
            .'nothing reports an error. This is the default when MAIL_MAILER is unset.',
        );

        $this->warnUnless(
            '' !== (string) config('mail.from.address'),
            '' !== (string) config('mail.from.address')
                ? 'There is a fallback From address'
                : 'There is no fallback From address',
            'MAIL_FROM_ADDRESS is empty. Each venue supplies its own sender, but anything the '
            .'platform sends on its own behalf — an invitation, a password reset — has no address '
            .'to come from.',
        );

        $senders = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('seatmap.messaging.sender_domains', ''))
        )));

        $this->note(
            $senders
                ? 'Authorised to send as: '.implode(', ', $senders).'. Each needs this server in its '
                  .'SPF record and its DKIM key here, or those venues\' mail lands in spam.'
                : 'SEATMAP_SENDER_DOMAINS is empty, which is the safe setting: every venue still gets '
                  .'its own name in the From line and its own address in Reply-To.',
        );
    }

    private function hosts(): void
    {
        $panel = (array) config('seatmap.sites.panel_hosts', []);

        $this->warnUnless(
            [] !== $panel,
            $panel ? 'The panel answers only on '.implode(', ', $panel) : 'The panel answers on any hostname',
            'SEATMAP_PANEL_HOSTS is empty, so the control panel answers on every hostname that is '
            .'not a published site — including a bare IP address and any domain somebody points '
            .'here. Name the panel\'s own hostname.',
        );

        $this->warnUnless(
            'https' === config('seatmap.sites.scheme'),
            'https' === config('seatmap.sites.scheme')
                ? 'Site addresses are built as https'
                : 'Site addresses are built as '.config('seatmap.sites.scheme'),
            'SEATMAP_SITE_SCHEME is `'.config('seatmap.sites.scheme').'`. Every link this platform '
            .'writes into an email or a payment callback would be plain HTTP.',
        );

        $this->warnUnless(
            '' !== (string) config('seatmap.sites.default_domain', ''),
            ($domain = (string) config('seatmap.sites.default_domain', ''))
                ? 'New sites get a subdomain of '.$domain
                : 'A new site has no address of its own',
            'SEATMAP_SITES_DOMAIN is empty, so a new account\'s site has no address until somebody '
            .'verifies a custom domain for it — and a site with no address cannot go live.',
        );

        $this->check(
            (bool) config('seatmap.webhooks.verify_destination', false) || ! app()->isProduction(),
            'Webhook addresses are checked before they are called',
            'SEATMAP_WEBHOOK_VERIFY_DESTINATION is false in production. A webhook address is then '
            .'neither resolved nor required to be https, which makes the panel a way to have this '
            .'server fetch any address an organiser types — its own cloud metadata endpoint '
            .'included.',
        );
    }

    private function disk(): void
    {
        foreach (['storage/logs', 'storage/framework', 'bootstrap/cache'] as $path) {
            $this->check(
                is_writable(base_path($path)),
                $path.' is writable',
                base_path($path).' is not writable by the web server user.',
            );
        }

        $this->warnUnless(
            is_file(public_path('checkin/index.html')),
            'The door scanner is built',
            'public/checkin is empty, so /checkin is a 404 and no volunteer can pair a phone. It is '
            .'a build artefact rather than source: run checkin-app/build.sh as part of deploying.',
        );

        // Said rather than checked, because there is nothing to check: this platform serves its own
        // stylesheets from public/ and uses neither a bundler nor a linked storage directory. An
        // operator who assumes otherwise spends an afternoon on a step that does not exist.
        $this->note(
            'Neither `npm run build` nor `php artisan storage:link` is part of deploying this: the '
            .'panel and the sites are served as plain files from public/, and an uploaded picture is '
            .'handed over by a route rather than from a linked directory.',
        );
    }

    /**
     * Where the pictures go, and whether one can get there.
     *
     * The limits are the part worth checking rather than assuming. PHP refuses an oversized upload
     * in the web server, before any of this application runs — so a media limit above
     * `upload_max_filesize` is an organiser watching a film upload for two minutes and then getting
     * a blank page with no sentence on it, which is the worst failure this feature has.
     */
    private function media(): void
    {
        $disk = (string) config('media.disk');
        $driver = (string) config('filesystems.disks.'.$disk.'.driver');

        if ('local' === $driver) {
            $root = (string) config('filesystems.disks.'.$disk.'.root');

            if (! is_dir($root)) {
                @mkdir($root, 0775, true);
            }

            $this->check(
                is_dir($root) && is_writable($root),
                'The media disk is writable — '.$root,
                $root.' cannot be written to by the web server user, so every upload fails. It is '
                .'also a local directory: behind more than one web server, set SEATMAP_MEDIA_DISK to '
                .'a bucket, or a poster will appear on every other page load.',
            );
        } else {
            $this->pass('The media disk is '.$disk.' ('.$driver.')');
        }

        $this->check(
            function_exists('imagewebp') && function_exists('imagejpeg') && function_exists('imagepng'),
            'GD can write JPEG, PNG and WebP',
            'This build of GD is missing one of imagejpeg, imagepng or imagewebp. Uploaded pictures '
            .'are re-encoded on the way in — metadata dropped, size capped — and without these the '
            .'upload fails rather than storing the original.',
        );

        $video = (int) config('media.max_video_megabytes');

        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $allowed = $this->megabytes((string) ini_get($setting));

            $this->warnUnless(
                $allowed >= $video,
                $allowed >= $video
                    ? 'PHP '.$setting.' is '.ini_get($setting).', which covers the '.$video.' MB media limit'
                    : 'PHP '.$setting.' is '.ini_get($setting).', below the '.$video.' MB media limit',
                'PHP '.$setting.' is '.ini_get($setting).' but SEATMAP_MEDIA_MAX_VIDEO_MB is '.$video
                .'. PHP refuses the request before this application sees it, so the organiser waits '
                .'for the upload and then gets a blank page rather than a sentence. Raise the PHP '
                .'setting, or lower the media limit to match.',
            );
        }
    }

    /** A php.ini size — "12M", "1G", "8388608" — as whole megabytes. */
    private function megabytes(string $value): float
    {
        $value = trim($value);

        if ('' === $value) {
            return 0;
        }

        $number = (float) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024,
            'm' => $number,
            'k' => $number / 1024,
            default => $number / 1048576,
        };
    }

    /* --------------------------------------------------------------------------- reporting */

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('  <fg=gray>'.$title.'</>');
    }

    private function check(bool $ok, string $title, string $detail): void
    {
        $ok ? $this->pass($title) : $this->record('fail', $title, $detail);
    }

    private function warnUnless(bool $ok, string $title, string $detail): void
    {
        $ok ? $this->pass($title) : $this->record('warn', $title, $detail);
    }

    private function warn_(string $title, string $detail): void
    {
        $this->record('warn', $title, $detail);
    }

    private function pass(string $title): void
    {
        $this->results[] = ['level' => 'ok', 'title' => $title, 'detail' => ''];
        $this->line('  <fg=green>ok  </> '.$title);
    }

    private function note(string $detail): void
    {
        $this->results[] = ['level' => 'note', 'title' => $detail, 'detail' => ''];

        foreach (explode(PHP_EOL, $this->wrap($detail)) as $index => $line) {
            $this->line($index ? '       '.$line : '  <fg=blue>note</> '.$line);
        }
    }

    private function record(string $level, string $title, string $detail): void
    {
        $this->results[] = ['level' => $level, 'title' => $title, 'detail' => $detail];

        $this->line(sprintf(
            '  <fg=%s>%s</> %s',
            'fail' === $level ? 'red' : 'yellow',
            'fail' === $level ? 'FAIL' : 'warn',
            $title,
        ));

        foreach (explode(PHP_EOL, $this->wrap($detail)) as $line) {
            $this->line('       <fg=gray>'.$line.'</>');
        }
    }

    /**
     * Wrapped to a width that survives an 80-column terminal, and wrapped per line so that the
     * deliberate line breaks in the scheduler note stay where they were put.
     */
    private function wrap(string $text): string
    {
        return implode(PHP_EOL, array_map(
            fn (string $line) => wordwrap($line, 88),
            explode(PHP_EOL, $text),
        ));
    }

    private function verdict(): int
    {
        $failed = count(array_filter($this->results, fn ($r) => 'fail' === $r['level']));
        $warned = count(array_filter($this->results, fn ($r) => 'warn' === $r['level']));

        $this->newLine();
        $this->line('  '.str_repeat('─', 68));
        $this->line(sprintf(
            '  %d checked, <fg=red>%d failing</>, <fg=yellow>%d to decide</>',
            count(array_filter($this->results, fn ($r) => 'note' !== $r['level'])),
            $failed,
            $warned,
        ));

        if ($failed) {
            $this->line('  <fg=red>Not fit to take money yet.</>');
        } elseif ($warned && $this->option('strict')) {
            $this->line('  <fg=yellow>Nothing is broken, but something has been left to a default.</>');
        } else {
            $this->line('  <fg=green>Fit to take money.</>'.($warned ? ' Read the warnings above anyway.' : ''));
        }

        $this->newLine();

        return $failed || ($warned && $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }
}
