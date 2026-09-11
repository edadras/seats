<?php

namespace App\Domain\Accounts;

use App\Models\AccountExport;
use App\Models\SeatMap;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Everything one account holds, in a zip they can walk away with.
 *
 * The rule is inclusion by default. Every table that carries a `tenant_id` goes in, and the four
 * that do not are named below with a reason — because a promise to hand somebody their data cannot
 * be kept by a list that has to be extended each time a feature is added. A list of what to include
 * would silently omit whatever was built last month; a list of what to leave out is four lines long
 * and reviewable in one sitting.
 *
 * What is left out is never left out quietly. A credential is replaced with a marker in its own
 * column, so an organiser can see that they had SMS configured without being handed the key to
 * somebody's account — the row is the truth about their setup, the secret is not theirs to carry to
 * a competitor's platform and is not ours to hand over in a file that will sit in an inbox.
 *
 * Seating plans are written twice: once as the row they are stored in, and once as the JSON
 * geometry itself under `seat-maps/`. That second copy is the only part of this archive that is
 * portable in the sense that matters — a CSV of orders can be read by anything, and a hall nobody
 * can redraw is a hall somebody has to survey again.
 */
class AccountExporter
{
    /**
     * Tables that do not go in, and why.
     *
     * Deliberately short. Anything longer is a list that will rot: the next feature's table will be
     * forgotten, and the organiser will never know what was missing.
     */
    private const SKIP = [
        // Nothing but key material: hashed secrets and the ids they are looked up by.
        'api_keys',
        // A replay cache with a few hours' life, about requests rather than about the account.
        'idempotency_keys',
        // The platform's own record of what its staff did. It names people who do not work for
        // this organiser, and the organiser's own audit log is in the archive.
        'platform_audit_logs',
        // The list of archives, inside an archive.
        'account_exports',
    ];

    /**
     * Columns replaced with a marker wherever they appear.
     *
     * By name rather than by table, because the shape of a secret does not change with where it is
     * kept, and a rule stated once cannot be forgotten in the eleventh place.
     */
    private const REDACT = [
        'secret', 'signing_secret', 'client_secret', 'password', 'credentials',
        'token', 'token_hash', 'verification_token', 'settings',
    ];

    /** What a redacted column says instead. Said out loud, because silence reads as "nothing here". */
    private const MARKER = '[not exported: a credential or a one-time token]';

    /** Tables whose `settings` really are the organiser's own content rather than credentials. */
    private const KEEP_SETTINGS = ['tenants', 'events'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Build the archive, now, in this request.
     *
     * Synchronously and on purpose. An export is asked for by somebody sitting in front of the
     * screen who is about to leave, and a job queue between them and their own history is one more
     * thing that can be down on the day they need it. It is rate limited at the route instead.
     */
    public function build(Tenant $tenant, ?string $userId = null): AccountExport
    {
        // The whole of it inside the account, rather than the first line only: a model saved
        // outside a tenant context carries a scope with nothing in it, and an update that matches
        // no rows is the kind of failure that leaves a row saying "building" for ever.
        return $this->tenants->runAs($tenant, fn () => $this->make($tenant, $userId));
    }

    private function make(Tenant $tenant, ?string $userId): AccountExport
    {
        $export = AccountExport::create([
            'tenant_id' => $tenant->id,
            'requested_by' => $userId,
            'status' => 'building',
            'expires_at' => now()->addDays($this->days()),
        ]);

        try {
            [$path, $bytes, $contents] = $this->write($tenant, $export);

            $export->forceFill([
                'status' => 'ready',
                'path' => $path,
                'bytes' => $bytes,
                'contents' => $contents,
                'ready_at' => now(),
            ])->save();

            $this->audit->record('account.exported', $export, [
                'bytes' => $bytes,
                'tables' => count($contents),
            ]);
        } catch (Throwable $e) {
            report($e);

            // Recorded rather than thrown away: an organiser who asked for their data and got
            // nothing needs to see that it was tried and what went wrong, not an empty list.
            $export->forceFill([
                'status' => 'failed',
                'error' => Str::limit($e->getMessage(), 500),
            ])->save();
        }

        return $export->fresh();
    }

    /** How long an archive and its link live. */
    public function days(): int
    {
        return max(1, (int) config('seatmap.accounts.export_days', 7));
    }

    /** The file itself, for the download route. Null when there is nothing to hand over. */
    public function fileFor(AccountExport $export): ?string
    {
        if (! $export->isReady()) {
            return null;
        }

        $path = storage_path('app/private/'.ltrim((string) $export->path, '/'));

        return is_file($path) ? $path : null;
    }

    /**
     * Throw away the archives whose time is up, and the rows that described them.
     *
     * Both, together: a row promising a file that is gone is worse than neither, because the screen
     * would offer a download that 404s.
     *
     * @return int how many went
     */
    public function sweep(): int
    {
        $gone = 0;

        $expired = $this->tenants->runUnscoped(
            fn () => AccountExport::withoutGlobalScopes()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->get()
        );

        foreach ($expired as $export) {
            $path = storage_path('app/private/'.ltrim((string) $export->path, '/'));

            if ($export->path && is_file($path)) {
                @unlink($path);
            }

            $this->tenants->runUnscoped(fn () => AccountExport::withoutGlobalScopes()
                ->whereKey($export->id)->delete());

            $gone++;
        }

        return $gone;
    }

    /* --------------------------------------------------------------------------- the archive */

    /**
     * @return array{0: string, 1: int, 2: array<string, int>}
     */
    private function write(Tenant $tenant, AccountExport $export): array
    {
        $relative = 'account-exports/'.$tenant->id.'/'.$export->id.'.zip';
        $absolute = storage_path('app/private/'.$relative);

        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0775, true);
        }

        $zip = new ZipArchive;

        if (true !== $zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('The archive could not be opened for writing.');
        }

        $contents = [];

        // The account itself first: one row, and the only table in the archive without a tenant id
        // to find it by, because it is the thing the id points at.
        $contents['account'] = $this->addTable($zip, 'tenants', 'id', $tenant->id, 'account');

        foreach ($this->tables() as $table) {
            $contents[$table] = $this->addTable($zip, $table, 'tenant_id', $tenant->id);
        }

        $contents['seat-maps'] = $this->addCharts($zip, $tenant);

        $zip->addFromString('manifest.json', (string) json_encode([
            'account' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug],
            'exported_at' => now()->toIso8601String(),
            'expires_at' => $export->expires_at?->toIso8601String(),
            'rows' => $contents,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $zip->addFromString('README.txt', $this->readme($tenant, $contents));
        $zip->close();

        return [$relative, (int) filesize($absolute), $contents];
    }

    /**
     * Every tenant-scoped table this database has, asked of the database rather than listed here.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $rows = DB::select(
            'select table_name from information_schema.columns '.
            'where table_schema = current_schema() and column_name = ? order by table_name',
            ['tenant_id'],
        );

        return array_values(array_diff(
            array_map(fn ($row) => $row->table_name, $rows),
            self::SKIP,
        ));
    }

    /** One CSV, streamed through a temporary file so a large table never sits in memory. */
    private function addTable(ZipArchive $zip, string $table, string $column, string $value, ?string $as = null): int
    {
        $columns = Schema::getColumnListing($table);

        if ([] === $columns) {
            return 0;
        }

        $handle = fopen('php://temp/maxmemory:4194304', 'w+b');

        // The same byte order mark every other export on this platform writes, so a spreadsheet on
        // Windows opens Persian and Arabic as text rather than as mojibake.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $columns);

        $rows = 0;

        DB::table($table)
            ->where($column, $value)
            ->orderBy('id')
            ->chunk(1000, function ($chunk) use ($handle, $columns, $table, &$rows) {
                foreach ($chunk as $row) {
                    fputcsv($handle, array_map(
                        fn (string $name) => $this->cell($table, $name, $row->{$name} ?? null),
                        $columns,
                    ));

                    $rows++;
                }
            });

        rewind($handle);
        $zip->addFromString('data/'.($as ?? $table).'.csv', (string) stream_get_contents($handle));
        fclose($handle);

        return $rows;
    }

    /** One value, with anything that is a credential replaced rather than omitted. */
    private function cell(string $table, string $column, mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if ('settings' === $column && in_array($table, self::KEEP_SETTINGS, true)) {
            return (string) $value;
        }

        foreach (self::REDACT as $name) {
            if ($column === $name || str_ends_with($column, '_'.$name)) {
                return self::MARKER;
            }
        }

        return is_scalar($value) ? (string) $value : (string) json_encode($value);
    }

    /**
     * The halls, as the geometry they are drawn from.
     *
     * The published version of each plan, because that is the one that sold tickets. A draft nobody
     * published is in the CSV like everything else; it is not what the room was.
     */
    private function addCharts(ZipArchive $zip, Tenant $tenant): int
    {
        return $this->tenants->runAs($tenant, function () use ($zip) {
            $written = 0;

            SeatMap::with('publishedVersion')->orderBy('name')->chunk(50, function ($maps) use ($zip, &$written) {
                foreach ($maps as $map) {
                    $version = $map->publishedVersion;

                    if (! $version) {
                        continue;
                    }

                    $zip->addFromString(
                        'seat-maps/'.(Str::slug($map->name) ?: 'plan').'-v'.$version->version.'.json',
                        (string) json_encode([
                            'name' => $map->name,
                            'version' => $version->version,
                            'published_at' => $version->published_at?->toIso8601String(),
                            'geometry' => $version->geometry,
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );

                    $written++;
                }
            });

            return $written;
        });
    }

    /** @param  array<string, int>  $contents */
    private function readme(Tenant $tenant, array $contents): string
    {
        $lines = [
            $tenant->name.' — a copy of everything this account holds',
            'Taken on '.now()->toDayDateTimeString().'.',
            '',
            'data/      one comma-separated file per table, with the column names on the first line.',
            'seat-maps/ every published seating plan, as the geometry it is drawn from.',
            'manifest.json  what went in, counted.',
            '',
            'Anything that was a password, a gateway key or a one-time token reads',
            '"'.self::MARKER.'" in its own column: the row still tells you what you had set up.',
            '',
            'What is in it:',
        ];

        foreach ($contents as $name => $rows) {
            $lines[] = sprintf('  %-28s %d', $name, $rows);
        }

        return implode("\n", $lines)."\n";
    }
}
