<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Reports\ReportRunner;
use App\Domain\Reports\SourceRegistry;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Modules\Contracts\ReportSource;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports: the sources you may build from, the definitions you saved, and the rows they return.
 *
 * There is no query box here and there will not be one (ADR-0006). A definition names fields by
 * key; the runner looks each one up in the source's declaration and uses the expression written
 * there. A field that is not declared never reaches the database.
 *
 * Permission is per source, not per screen: `reports.orders.view` is not `reports.attendance.view`,
 * so a volunteer coordinator can be given attendance without being given revenue. Saving a report
 * cannot widen that — a viewer needs the source's permission whoever saved it.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly ReportRunner $runner,
        private readonly AuditLogger $audit,
    ) {}

    /** Every source this person may actually read, with its fields already translated. */
    public function sources(Request $request)
    {
        $described = [];

        foreach ($this->sources->all() as $key => $source) {
            if (! app(\App\Support\Access\Gate::class)->allows($request, $source->permission())) {
                continue;
            }

            $described[] = [
                'key' => $key,
                'name' => __($source->labelKey()),
                'description' => method_exists($source, 'descriptionKey')
                    ? __($source->descriptionKey())
                    : null,
                'permission' => $source->permission(),
                'dimensions' => $this->describe($source->dimensions()),
                'measures' => $this->describe($source->measures()),
                'filters' => $this->describe($source->filters()),
            ];
        }

        if ([] === $described) {
            throw ApiException::forbidden('No reports are available to your role.', 'forbidden_permission');
        }

        return response()->json([
            'data' => $described,
            'max_rows' => ReportRunner::MAX_ROWS,
            'max_export_rows' => ReportRunner::MAX_EXPORT_ROWS,
        ]);
    }

    /** Run a definition that has not been saved — what the builder does as you tick boxes. */
    public function run(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:60'],
            'definition' => ['required', 'array'],
        ]);

        $source = $this->source($request, $data['source']);

        return response()->json(
            $this->runner->run($source, $data['definition']) + ['source' => $source->key()]
        );
    }

    /* --------------------------------------------------------------------- saved reports */

    public function index(Request $request)
    {
        return response()->json([
            'data' => Report::with('author')->orderBy('name')->get()
                ->filter(fn (Report $report) => $this->readable($request, $report))
                ->map(fn (Report $report) => $this->present($report))
                ->values(),
        ]);
    }

    public function show(Request $request, Report $report)
    {
        $this->assertReadable($request, $report);

        return response()->json($this->present($report));
    }

    /** Run a saved one. The definition is validated again, because a source can change. */
    public function runSaved(Request $request, Report $report)
    {
        $source = $this->assertReadable($request, $report);

        return response()->json(
            $this->runner->run($source, $report->definition ?? []) + [
                'report' => ['id' => $report->id, 'name' => $report->name],
                'source' => $source->key(),
            ]
        );
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'reports.build');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'source' => ['required', 'string', 'max:60'],
            'definition' => ['required', 'array'],
        ]);

        $source = $this->source($request, $data['source']);

        // Validated before it is stored: a definition that cannot run is not worth saving, and
        // finding out now is kinder than finding out on a screen next week.
        $this->runner->plan($source, $data['definition']);

        $report = Report::create([
            'name' => $data['name'],
            'source_key' => $source->key(),
            'definition' => $data['definition'],
            'created_by' => $request->user()->id,
        ]);

        $this->audit->record('report.created', $report, [
            'name' => $report->name,
            'source' => $report->source_key,
        ]);

        return response()->json($this->present($report), 201);
    }

    public function update(Request $request, Report $report)
    {
        $this->authorize($request, 'reports.build');
        $this->assertReadable($request, $report);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'definition' => ['sometimes', 'array'],
        ]);

        if (isset($data['definition'])) {
            $this->runner->plan($this->source($request, $report->source_key), $data['definition']);
        }

        $report->fill($data);
        $this->audit->recordChange('report.updated', $report);
        $report->save();

        return response()->json($this->present($report->fresh()));
    }

    public function destroy(Request $request, Report $report)
    {
        $this->authorize($request, 'reports.build');
        $this->assertReadable($request, $report);

        $name = $report->name;
        $report->delete();

        $this->audit->record('report.deleted', null, ['name' => $name]);

        return response()->json(['deleted' => true]);
    }

    /**
     * The same definition, as a file.
     *
     * Streamed rather than assembled in memory, and capped at a higher number than the screen
     * because a spreadsheet is where somebody goes when they want everything. It is the same
     * definition and the same runner — two implementations of what a report means is how an
     * export and its screen start disagreeing (ADR-0006 §4).
     */
    public function export(Request $request, Report $report): StreamedResponse
    {
        $source = $this->assertReadable($request, $report);

        $result = $this->runner->run(
            $source,
            $report->definition ?? [],
            ReportRunner::MAX_EXPORT_ROWS
        );

        $this->audit->record('report.exported', $report, [
            'name' => $report->name,
            'rows' => count($result['rows']),
        ]);

        $filename = \Illuminate\Support\Str::slug($report->name) ?: 'report';

        return response()->streamDownload(function () use ($result) {
            $handle = fopen('php://output', 'wb');

            // A BOM, so a spreadsheet on Windows opens Persian and Arabic column headings as text
            // rather than as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_column($result['columns'], 'label'));

            foreach ($result['rows'] as $row) {
                fputcsv($handle, array_map(
                    fn (array $column) => $row[$column['alias']] ?? '',
                    $result['columns']
                ));
            }

            fclose($handle);
        }, $filename.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function describe(array $fields): array
    {
        return array_values(array_map(fn (string $key) => array_filter([
            'key' => $key,
            'label' => __($fields[$key]['label_key']),
            'type' => $fields[$key]['type'] ?? null,
            'aggregate' => $fields[$key]['aggregate'] ?? null,
            'format' => $fields[$key]['format'] ?? null,
            'options' => $fields[$key]['options'] ?? null,
        ], fn ($value) => null !== $value), array_keys($fields)));
    }

    private function source(Request $request, string $key): ReportSource
    {
        $source = $this->sources->find($key);

        if (! $source) {
            throw ApiException::unprocessable('unknown_source', 'There is no such report source.');
        }

        // The source's own permission, every time it is used — building, saving, running, or
        // exporting. Sharing a report cannot widen what its reader may see.
        $this->authorize($request, $source->permission());

        return $source;
    }

    private function assertReadable(Request $request, Report $report): ReportSource
    {
        return $this->source($request, $report->source_key);
    }

    private function readable(Request $request, Report $report): bool
    {
        $source = $this->sources->find($report->source_key);

        return $source
            && app(\App\Support\Access\Gate::class)->allows($request, $source->permission());
    }

    private function present(Report $report): array
    {
        // Loaded here rather than at each call site: `author` is a nullable relation on a model
        // that arrives from four different places — a listing, a fetch, a save, an edit — and lazy
        // loading is off, so the one path that forgot would be a 500 that appears only once an
        // account has saved its first report.
        $report->loadMissing('author');

        return [
            'id' => $report->id,
            'name' => $report->name,
            'source' => $report->source_key,
            'definition' => $report->definition,
            'author' => $report->author?->name,
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];
    }
}
