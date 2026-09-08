<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Answers "who changed this, when, and from where" for every mutation (threat T10) — and, since
 * the access work, *what* changed.
 *
 * That last one is not a nicety. "Someone updated an event" answers nothing at four in the morning
 * when a show has the wrong price on it. A record that names the fields, and their before and
 * after where the value is safe to keep, is the difference between a log and a diary.
 */
class AuditLogger
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function record(
        string $action,
        ?object $subject = null,
        array $context = [],
        ?string $tenantId = null,
        ?array $changes = null,
    ): void {
        $request = request();

        [$actorType, $actorId] = $this->actor($request);

        AuditLog::create([
            'tenant_id' => $tenantId ?? $this->tenantContext->id(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $this->label($subject),
            'request_id' => $request?->attributes->get('request_id'),
            'ip' => $request?->ip(),
            'context' => $this->redact($context),
            'changes' => null === $changes ? null : $this->redact($changes),
            'created_at' => now(),
        ]);
    }

    /**
     * Record a change to a model, with what actually moved.
     *
     * Uses Eloquent's own dirty tracking, so it records what the database is about to be told
     * rather than what the caller believes they asked for — those differ often enough to matter,
     * and the second one is the version that hides a bug.
     *
     * Call it *before* save(): after, there is nothing dirty left to read.
     */
    public function recordChange(string $action, Model $subject, array $context = []): void
    {
        $changes = [];

        foreach ($subject->getDirty() as $field => $after) {
            $changes[$field] = [
                'from' => $subject->getOriginal($field),
                'to' => $after,
            ];
        }

        $this->record($action, $subject, $context, changes: $changes ?: null);
    }

    /**
     * Something a person can recognise the subject by.
     *
     * A row of UUIDs is a log nobody reads. `name` covers most of what this system stores; the
     * rest name themselves in a way worth showing.
     */
    private function label(?object $subject): ?string
    {
        if (! $subject instanceof Model) {
            return null;
        }

        foreach (['name', 'hostname', 'title', 'external_order_id', 'email', 'key', 'module_key', 'slug'] as $field) {
            $value = $subject->getAttribute($field);

            if (is_string($value) && '' !== $value) {
                return mb_substr($value, 0, 160);
            }
        }

        return null;
    }

    /** @return array{0: string, 1: ?string} */
    private function actor(?Request $request): array
    {
        if (! $request) {
            return ['system', null];
        }

        if ($key = $request->attributes->get('api_key')) {
            return ['api_key', $key->key_id];
        }

        if ($device = $request->attributes->get('checkin_device')) {
            return ['device', $device->id];
        }

        if ($user = $request->user()) {
            return ['user', (string) $user->getKey()];
        }

        return ['system', null];
    }

    /**
     * Secrets must never reach the audit trail, even by accident.
     *
     * The parent key is carried down, because a change is recorded as `api_key => {from, to}` and
     * checking only the leaf would see `from` and `to` — two words that look innocent and hold a
     * credential.
     */
    private function redact(array $context, ?string $parentKey = null): array
    {
        foreach ($context as $key => $value) {
            $name = strtolower((string) $key);

            if ($this->isSensitive($name) || ($parentKey && $this->isSensitive($parentKey))) {
                $context[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $context[$key] = $this->redact($value, $name);
            }
        }

        return $context;
    }

    private function isSensitive(string $key): bool
    {
        foreach (['secret', 'password', 'token', 'signature', 'pairing_code', 'api_key'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
