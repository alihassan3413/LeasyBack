<?php

namespace App\Modules\PartnerApi\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * The one pagination shape every Partner API list endpoint returns.
 *
 * Deliberately not Laravel's default paginator JSON: that carries absolute
 * `first_page_url`/`links` built from the app URL, which a partner behind a
 * gateway would follow to the wrong host. Partners page by number here, and
 * the keys match the meta block VehicleService::paginateVehiclesWithOrders
 * already produces for the portal, so the two surfaces read the same.
 */
final class PartnerPagination
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array<string, int|null>
     */
    public static function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    /**
     * Clamped rather than validated: an out-of-range `per_page` is a partner
     * asking for more than we serve, not a malformed request, and failing it
     * would break a poll loop over a value that has no effect on correctness.
     */
    public static function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', (string) self::DEFAULT_PER_PAGE);

        if ($requested < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($requested, self::MAX_PER_PAGE);
    }

    public static function page(Request $request): int
    {
        return max(1, (int) $request->query('page', '1'));
    }
}
