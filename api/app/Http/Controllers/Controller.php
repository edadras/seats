<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Support\Access\Gate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Refuse unless the caller holds this permission.
     *
     * Separate from tenant scoping, and both are required: scoping decides *whose* data a caller
     * sees, this decides what they may do with it. A box-office member can find a booking and put
     * a seat back on sale, and must not be able to republish the map that seat is on.
     *
     * The permission is named at the call site rather than derived from the route, because the
     * mapping from "what this endpoint does" to "what it takes to be allowed to" is a judgement,
     * and a judgement written down beside the code is one somebody can disagree with in review.
     */
    protected function authorize(Request $request, string $permission): void
    {
        if (! app(Gate::class)->allows($request, $permission)) {
            throw ApiException::forbidden(
                'Your role does not permit that.',
                'forbidden_permission',
            );
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
