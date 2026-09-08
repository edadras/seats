<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\TenantUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Role check for anything that changes tenant data.
     *
     * This is separate from tenant scoping and both are required: scoping decides *whose* data a
     * caller sees, this decides whether a member may change it. A `viewer` can read their own
     * tenant's events but must not publish one.
     */
    protected function authorizeWrite(Request $request): void
    {
        $membership = $request->attributes->get('membership');

        if (! $membership instanceof TenantUser || ! $membership->canWrite()) {
            throw ApiException::forbidden('Your role does not permit changes to this organiser.');
        }
    }

    protected function paginated(LengthAwarePaginator $paginator, ?callable $map = null): \Illuminate\Http\JsonResponse
    {
        $items = $paginator->getCollection();

        return response()->json([
            'data' => $map ? $items->map($map)->values() : $items->values(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
