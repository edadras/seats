<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The tariffs: what a plan costs and what it lets an organiser do.
 *
 * Limits are the same keys the platform actually enforces, not a marketing list — a plan that
 * promises something nothing checks is a plan that gets sold and then argued about.
 *
 * A plan in use is never deleted. It is deactivated, which takes it off the signup screen and
 * leaves every organiser on it exactly where they were; deleting it would leave subscriptions
 * pointing at nothing, and "what am I paying for" is not a question a billing system should be
 * unable to answer.
 */
class PlanController extends Controller
{
    /**
     * The limits a plan may set — the same list the platform enforces (`PlanLimits::KEYS`), so
     * this screen cannot offer a promise nothing checks.
     */
    private const LIMITS = \App\Support\Plans\PlanLimits::KEYS;

    public function index(Request $request)
    {
        return response()->json([
            'data' => Plan::orderBy('price_amount')->get()->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'key' => $plan->key,
                'name' => $plan->name,
                'price_amount' => $plan->price_amount,
                'currency' => $plan->currency,
                'interval' => $plan->interval,
                'limits' => $plan->limits ?? [],
                'is_active' => $plan->is_active,
                'subscribers' => Subscription::where('plan_id', $plan->id)->count(),
            ])->values(),
            'limit_keys' => self::LIMITS,
        ]);
    }

    public function store(Request $request)
    {
        $this->assertOperator($request);

        $data = $this->validatePlan($request, creating: true);

        $plan = Plan::create([
            'key' => Str::slug($data['key']),
            'name' => $data['name'],
            'price_amount' => $data['price_amount'],
            'currency' => mb_strtoupper($data['currency']),
            'interval' => $data['interval'],
            'limits' => $this->limits($data['limits'] ?? []),
            'is_active' => $data['is_active'] ?? true,
        ]);

        PlatformAuditLog::write($request->user()->id, 'plan.created', null, [
            'key' => $plan->key, 'price' => $plan->price_amount, 'currency' => $plan->currency,
        ], $request->ip());

        return response()->json($this->present($plan), 201);
    }

    public function update(Request $request, string $planId)
    {
        $this->assertOperator($request);

        $plan = Plan::findOr($planId, fn () => throw ApiException::notFound('Unknown plan.', 'unknown_plan'));
        $data = $this->validatePlan($request, creating: false);

        /*
         * Changing a price changes what existing subscribers pay at their next renewal, which is a
         * thing somebody should be able to find out later. So the old price is in the log, not
         * just the new one.
         */
        $before = ['price_amount' => $plan->price_amount, 'currency' => $plan->currency];

        $plan->fill(array_filter([
            'name' => $data['name'] ?? null,
            'price_amount' => $data['price_amount'] ?? null,
            'currency' => isset($data['currency']) ? mb_strtoupper($data['currency']) : null,
            'interval' => $data['interval'] ?? null,
        ], fn ($value) => null !== $value));

        if (array_key_exists('limits', $data)) {
            $plan->limits = $this->limits($data['limits']);
        }

        if (array_key_exists('is_active', $data)) {
            $plan->is_active = $data['is_active'];
        }

        $plan->save();

        PlatformAuditLog::write($request->user()->id, 'plan.updated', null, [
            'key' => $plan->key,
            'from' => $before,
            'to' => ['price_amount' => $plan->price_amount, 'currency' => $plan->currency],
            'active' => $plan->is_active,
        ], $request->ip());

        return response()->json($this->present($plan->fresh()));
    }

    public function destroy(Request $request, string $planId)
    {
        $this->assertOperator($request);

        $plan = Plan::findOr($planId, fn () => throw ApiException::notFound('Unknown plan.', 'unknown_plan'));

        $subscribers = Subscription::where('plan_id', $plan->id)->count();

        if ($subscribers > 0) {
            throw ApiException::conflict(
                'plan_in_use',
                'Organisers are on this plan. Deactivate it instead — that takes it off the signup screen and leaves them where they are.',
                ['subscribers' => $subscribers],
            );
        }

        $key = $plan->key;
        $plan->delete();

        PlatformAuditLog::write($request->user()->id, 'plan.deleted', null, ['key' => $key], $request->ip());

        return response()->json(['deleted' => true]);
    }

    /* --------------------------------------------------------------------------- helpers */

    private function validatePlan(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'key' => [$creating ? 'required' : 'prohibited', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'name' => [$required, 'string', 'max:80'],
            'price_amount' => [$required, 'integer', 'min:0'],
            'currency' => [$required, 'string', 'size:3', 'alpha'],
            'interval' => [$required, 'in:month,year'],
            'limits' => ['sometimes', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** Only the keys the platform enforces, and null meaning "no limit". */
    private function limits(array $given): array
    {
        $clean = [];

        foreach (self::LIMITS as $key) {
            if (! array_key_exists($key, $given)) {
                continue;
            }

            $clean[$key] = null === $given[$key] ? null : max(0, (int) $given[$key]);
        }

        return $clean;
    }

    private function assertOperator(Request $request): void
    {
        $admin = $request->attributes->get('platform_admin');

        if (! $admin instanceof PlatformAdmin || ! $admin->mayChange()) {
            throw ApiException::denied('support_may_not_change', 'Support accounts can look, not change.');
        }
    }

    private function present(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'key' => $plan->key,
            'name' => $plan->name,
            'price_amount' => $plan->price_amount,
            'currency' => $plan->currency,
            'interval' => $plan->interval,
            'limits' => $plan->limits ?? [],
            'is_active' => $plan->is_active,
        ];
    }
}
