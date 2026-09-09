<?php

namespace App\Domain\Questions;

use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\ExternalOrder;
use App\Models\QuestionAnswer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asking the organiser's own questions at checkout, and keeping the answers.
 *
 * The shape of a checkout form is data here rather than code, so an organiser who needs to know
 * about wheelchair spaces and one who needs car registrations get the same feature rather than two
 * bespoke ones.
 *
 * Validation happens before the money moves. A required question left blank has to stop a purchase
 * — otherwise the answer is collected on a page nobody comes back to — and it has to stop it before
 * the gateway is involved, not after.
 */
class CheckoutQuestions
{
    /** @return \Illuminate\Support\Collection<int, EventQuestion> */
    public function forEvent(Event $event)
    {
        return EventQuestion::where('event_id', $event->id)
            ->where('status', 'active')
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * What to draw on the checkout page.
     *
     * Per-ticket questions are expanded per seat here rather than in the template, because the
     * template would then have to know which lines of a booking are seats and which are a quantity
     * of standing places — and it would get it wrong for the standing ones.
     *
     * @param  list<array{key: string, label: string}>  $places  one entry per ticket being bought
     * @return list<array<string, mixed>>
     */
    public function fields(Event $event, array $places): array
    {
        $fields = [];

        foreach ($this->forEvent($event) as $question) {
            if (! $question->isPerTicket()) {
                $fields[] = $this->field($question, 'q_'.$question->id, null);

                continue;
            }

            foreach ($places as $place) {
                $fields[] = $this->field(
                    $question,
                    'q_'.$question->id.'_'.$place['key'],
                    $place['label'],
                );
            }
        }

        return $fields;
    }

    private function field(EventQuestion $question, string $name, ?string $about): array
    {
        return [
            'id' => $question->id,
            'name' => $name,
            'label' => $question->label,
            'about' => $about,
            'help' => $question->help,
            'kind' => $question->kind,
            'choices' => $question->choices(),
            'required' => (bool) $question->required,
        ];
    }

    /**
     * Check the answers before anything is charged.
     *
     * @param  list<array{key: string, label: string}>  $places
     * @return array<string, string> the field name => the answer, ready to be written down
     *
     * @throws ValidationException
     */
    public function validate(Event $event, array $places, array $input): array
    {
        $answers = [];
        $errors = [];

        foreach ($this->fields($event, $places) as $field) {
            $value = $input[$field['name']] ?? null;

            $value = 'checkbox' === $field['kind']
                ? (($value && '0' !== $value) ? '1' : '')
                : trim((string) $value);

            // A choice question answered with something that was not offered is not an answer; it
            // is a browser sending whatever it liked.
            if ('choice' === $field['kind'] && '' !== $value && ! in_array($value, $field['choices'], true)) {
                $value = '';
            }

            if ($field['required'] && '' === $value) {
                $errors[$field['name']] = [__('site.questions.required', ['label' => $field['label']])];

                continue;
            }

            if ('' !== $value) {
                $answers[$field['name']] = mb_substr($value, 0, 2000);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $answers;
    }

    /**
     * Write the answers down: against the order, and against the seat each was asked about.
     *
     * Called once the allocations exist, because a per-ticket answer with no ticket to point at is
     * an answer nobody can find again. The form was drawn before they did, so the answers are keyed
     * by *place* — a seat id, or a numbered standing place — and translated here.
     *
     * @param  array<string, string>  $answers  as returned by validate()
     * @param  array<string, string>  $places   place key => the allocation it turned into
     */
    public function store(Event $event, ExternalOrder $order, array $answers, array $places = []): void
    {
        if ($answers === []) {
            return;
        }

        $questions = $this->forEvent($event)->keyBy('id');
        $rows = [];

        foreach ($answers as $name => $value) {
            // "q_<question>" or "q_<question>_<place>": the field name carries both, because an
            // HTML form is a flat list of names and nothing else.
            $parts = explode('_', $name, 3);
            $question = $questions->get($parts[1] ?? '');

            if (! $question) {
                continue;
            }

            $allocationId = isset($parts[2]) ? ($places[$parts[2]] ?? null) : null;

            if (isset($parts[2]) && ! $allocationId) {
                // The seat it was asked about is not on this order any more. Keeping the answer
                // against nothing would put it on the door list beside the wrong person.
                continue;
            }

            $rows[] = [
                'tenant_id' => $order->tenant_id,
                'event_id' => $event->id,
                'event_question_id' => $question->id,
                'external_order_row_id' => $order->id,
                'allocation_id' => $allocationId,
                'label' => $question->label,
                'value' => $value,
            ];
        }

        if ($rows === []) {
            return;
        }

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                QuestionAnswer::create($row);
            }
        });
    }
}
