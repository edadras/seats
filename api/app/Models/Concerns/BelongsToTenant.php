<?php

namespace App\Models\Concerns;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Mandatory tenant scoping.
 *
 * Two halves, and both matter:
 *  - a global scope, so no query can accidentally read across tenants;
 *  - a saving guard, so no row can be written without a tenant, or written to the wrong one.
 *
 * The guard is what makes the isolation testable: a bug that forgets `tenant_id` fails loudly at
 * write time instead of silently creating an orphan row visible to nobody (or, worse, to everyone).
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $context = app(TenantContext::class);

            if ($context->isUnscoped()) {
                return;
            }

            if (! $context->has()) {
                // No tenant bound and not explicitly unscoped: return nothing rather than
                // everything. Failing closed is the only safe default here.
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->getModel()->getTable().'.tenant_id', $context->id());
        });

        // Eloquent fires `saving` before `creating`, so filling and guarding both belong here —
        // splitting them across the two events would guard an id that has not been set yet.
        static::saving(function (Model $model) {
            $context = app(TenantContext::class);

            if (empty($model->tenant_id) && ! $model->exists) {
                $model->tenant_id = $context->idOrFail();
            }

            if (empty($model->tenant_id)) {
                throw new \RuntimeException(sprintf(
                    '%s cannot be saved without a tenant_id.', static::class
                ));
            }

            if (! $context->isUnscoped() && $context->has() && $model->tenant_id !== $context->id()) {
                throw new \RuntimeException(sprintf(
                    'Refusing to write %s belonging to tenant %s while acting for tenant %s.',
                    static::class, $model->tenant_id, $context->id()
                ));
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }
}
