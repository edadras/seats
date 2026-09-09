<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\QuestionAnswer;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The questions an event asks at checkout.
 *
 * Saved as a whole list, like the ticket types and the price zones: what a checkout asks is one
 * decision about the event, and editing it a row at a time is how a form ends up with last
 * season's question still on it.
 *
 * A question that has been answered is not deleted. The answers point at it, and an organiser who
 * removed it would leave a door list full of answers to a question nobody can read. Hiding it stops
 * it being asked and leaves what people said intact.
 */
class EventQuestionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, Event $event)
    {
        $this->authorize($request, 'events.view');

        $answered = $this->answerCounts($event);

        return response()->json([
            'data' => EventQuestion::where('event_id', $event->id)
                ->orderBy('position')
                ->get()
                ->map(fn (EventQuestion $question) => $this->present($question, $answered))
                ->values(),
        ]);
    }

    public function replace(Request $request, Event $event)
    {
        $this->authorize($request, 'events.manage');

        $data = $request->validate([
            'questions' => ['present', 'array', 'max:20'],
            'questions.*.id' => ['nullable', 'uuid'],
            'questions.*.label' => ['required', 'string', 'max:160'],
            'questions.*.help' => ['nullable', 'string', 'max:240'],
            'questions.*.kind' => ['required', Rule::in(['text', 'choice', 'checkbox'])],
            'questions.*.options' => ['nullable', 'array', 'max:40'],
            'questions.*.options.*' => ['string', 'max:120'],
            'questions.*.required' => ['sometimes', 'boolean'],
            'questions.*.scope' => ['required', Rule::in(['order', 'ticket'])],
            'questions.*.status' => ['sometimes', Rule::in(['active', 'hidden'])],
        ]);

        $answered = $this->answerCounts($event);
        $existing = EventQuestion::where('event_id', $event->id)->get()->keyBy('id');
        $keptIds = array_values(array_filter(array_column($data['questions'], 'id')));

        $blocked = $existing->keys()
            ->diff($keptIds)
            ->filter(fn (string $id) => ($answered[$id] ?? 0) > 0)
            ->values();

        if ($blocked->isNotEmpty()) {
            throw ApiException::conflict(
                'question_answered',
                'A question somebody has answered cannot be removed. Hide it instead.',
                ['question_ids' => $blocked->all()],
            );
        }

        DB::transaction(function () use ($event, $data, $existing, $keptIds) {
            EventQuestion::where('event_id', $event->id)
                ->whereNotIn('id', $keptIds ?: ['00000000-0000-0000-0000-000000000000'])
                ->delete();

            foreach ($data['questions'] as $position => $row) {
                $attributes = [
                    'label' => $row['label'],
                    'help' => $row['help'] ?? null,
                    'kind' => $row['kind'],
                    // Choices belong to a choice question and nowhere else: leaving them on a text
                    // question is leaving a trap for whoever changes its kind back.
                    'options' => 'choice' === $row['kind']
                        ? array_values(array_filter(array_map('trim', $row['options'] ?? [])))
                        : null,
                    'required' => (bool) ($row['required'] ?? false),
                    'scope' => $row['scope'],
                    'position' => $position,
                    'status' => $row['status'] ?? 'active',
                ];

                $question = ($row['id'] ?? null) ? $existing->get($row['id']) : null;

                if ($question) {
                    EventQuestion::whereKey($question->id)->update($attributes + ['updated_at' => now()]);

                    continue;
                }

                EventQuestion::create($attributes + [
                    'tenant_id' => $event->tenant_id,
                    'event_id' => $event->id,
                ]);
            }
        });

        $this->audit->record('event.questions_set', $event, [
            'count' => count($data['questions']),
        ]);

        return $this->index($request, $event);
    }

    /** @return array<string, int> */
    private function answerCounts(Event $event): array
    {
        return QuestionAnswer::where('event_id', $event->id)
            ->selectRaw('event_question_id, count(*) as answered')
            ->groupBy('event_question_id')
            ->pluck('answered', 'event_question_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function present(EventQuestion $question, array $answered): array
    {
        return [
            'id' => $question->id,
            'label' => $question->label,
            'help' => $question->help,
            'kind' => $question->kind,
            'options' => $question->choices(),
            'required' => (bool) $question->required,
            'scope' => $question->scope,
            'position' => $question->position,
            'status' => $question->status,
            // Why a row cannot be deleted, said on the row rather than in an error afterwards.
            'answered' => $answered[$question->id] ?? 0,
        ];
    }
}
