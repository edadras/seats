<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Domain\Reports\ReportRunner;
use App\Domain\Reports\SourceRegistry;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportPage;
use App\Support\Access\Gate;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Pages of reports (ADR-0006 §3).
 *
 * A page is a list of widgets over saved reports — the same block model the site builder uses, so
 * an organiser learns one editor rather than two. Reading a page runs every widget on it, each
 * through its own report's source permission: a page cannot become a way to see a report you may
 * not read. A widget whose report is out of reach is returned as a refusal in place, so the page
 * still renders and says plainly what is missing.
 */
class ReportPageController extends Controller
{
    /** The shapes a widget can take. Not a free string: the panel renders on this. */
    private const WIDGET_TYPES = ['table', 'bar', 'line', 'stat'];

    public function __construct(
        private readonly SourceRegistry $sources,
        private readonly ReportRunner $runner,
        private readonly AuditLogger $audit,
        private readonly Gate $gate,
    ) {}

    public function index(Request $request)
    {
        $this->authorize($request, 'reports.attendance.view');

        return response()->json([
            'data' => ReportPage::orderBy('name')->get()->map(fn (ReportPage $page) => [
                'id' => $page->id,
                'name' => $page->name,
                'slug' => $page->slug,
                'widgets' => $page->widgets ?? [],
                'updated_at' => $page->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** The page, with every widget already run. */
    public function show(Request $request, ReportPage $page)
    {
        $this->authorize($request, 'reports.attendance.view');

        $widgets = [];

        foreach ($page->widgets ?? [] as $widget) {
            $widgets[] = $this->runWidget($request, $widget);
        }

        return response()->json([
            'id' => $page->id,
            'name' => $page->name,
            'slug' => $page->slug,
            'widgets' => $widgets,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize($request, 'reports.build');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'widgets' => ['sometimes', 'array'],
        ]);

        $page = ReportPage::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'widgets' => $this->sanitise($data['widgets'] ?? []),
            'created_by' => $request->user()->id,
        ]);

        $this->audit->record('report_page.created', $page, ['name' => $page->name]);

        return response()->json($this->present($page), 201);
    }

    public function update(Request $request, ReportPage $page)
    {
        $this->authorize($request, 'reports.build');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'widgets' => ['sometimes', 'array'],
        ]);

        if (isset($data['name'])) {
            $page->name = $data['name'];
        }

        if (array_key_exists('widgets', $data)) {
            $page->widgets = $this->sanitise($data['widgets']);
        }

        $this->audit->recordChange('report_page.updated', $page);
        $page->save();

        return response()->json($this->present($page->fresh()));
    }

    public function destroy(Request $request, ReportPage $page)
    {
        $this->authorize($request, 'reports.build');

        $name = $page->name;
        $page->delete();

        $this->audit->record('report_page.deleted', null, ['name' => $name]);

        return response()->json(['deleted' => true]);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function runWidget(Request $request, array $widget): array
    {
        $shape = [
            'type' => $widget['type'] ?? 'table',
            'title' => $widget['title'] ?? null,
            'report_id' => $widget['report_id'] ?? null,
        ];

        $report = Report::whereKey($shape['report_id'])->first();

        if (! $report) {
            return $shape + ['error' => 'report_missing'];
        }

        $source = $this->sources->find($report->source_key);

        if (! $source) {
            return $shape + ['error' => 'unknown_source', 'title' => $shape['title'] ?? $report->name];
        }

        $shape['title'] = $shape['title'] ?: $report->name;

        // Each widget through its own source's permission. A page is not a way around that.
        if (! $this->gate->allows($request, $source->permission())) {
            return $shape + ['error' => 'forbidden_permission'];
        }

        try {
            return $shape + $this->runner->run($source, $report->definition ?? []);
        } catch (ApiException $e) {
            // One widget that cannot run must not take the page down with it.
            return $shape + ['error' => $e->errorCode()];
        }
    }

    private function sanitise(array $widgets): array
    {
        $clean = [];

        foreach (array_slice(array_values($widgets), 0, 24) as $widget) {
            if (! is_array($widget) || empty($widget['report_id']) || ! is_string($widget['report_id'])) {
                continue;
            }

            // Scoped through the tenant's own reports, so a widget cannot be pointed at another
            // organiser's report by guessing an id.
            if (! Report::whereKey($widget['report_id'])->exists()) {
                continue;
            }

            $clean[] = [
                'type' => in_array($widget['type'] ?? '', self::WIDGET_TYPES, true)
                    ? $widget['type']
                    : 'table',
                'report_id' => $widget['report_id'],
                'title' => isset($widget['title']) ? mb_substr((string) $widget['title'], 0, 120) : null,
                'width' => in_array($widget['width'] ?? '', ['half', 'full'], true)
                    ? $widget['width']
                    : 'full',
            ];
        }

        return $clean;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug(mb_substr($name, 0, 60)) ?: 'page';
        $slug = $base;
        $suffix = 2;

        while (ReportPage::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function present(ReportPage $page): array
    {
        return [
            'id' => $page->id,
            'name' => $page->name,
            'slug' => $page->slug,
            'widgets' => $page->widgets ?? [],
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }
}
