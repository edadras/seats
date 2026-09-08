<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;

/**
 * Answers "who changed this, when, and from where" for every mutation (threat T10).
 */
class AuditLogger
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function record(
        string $action,
        ?object $subject = null,
        array $context = [],
        ?string $tenantId = null,
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
            'request_id' => $request?->attributes->get('request_id'),
            'ip' => $request?->ip(),
            'context' => $this->redact($context),
            'created_at' => now(),
        ]);
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

    /** Secrets must never reach the audit trail, even by accident. */
    private function redact(array $context): array
    {
        $sensitive = ['secret', 'password', 'token', 'signature', 'secret_hash', 'pairing_code'];

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = $this->redact($value);
                continue;
            }

            foreach ($sensitive as $needle) {
                if (str_contains(strtolower((string) $key), $needle)) {
                    $context[$key] = '[redacted]';
                    break;
                }
            }
        }

        return $context;
    }
}
