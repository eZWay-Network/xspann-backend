<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait PaginatesApiResponses
{
    public function paginated(LengthAwarePaginator $paginator, callable $map, Request $request): JsonResponse
    {
        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn ($item) => $map($item)->resolve($request))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
