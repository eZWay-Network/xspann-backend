<?php

namespace App\Http\Requests\Staff;

use App\Services\Staff\{Context, StaffException};
use Spark\Facades\DB;
use Spark\Http\Request;
use Spark\Paginator\Cursor;

final class ListFilters
{
    public static function read(Request $request, array $choices = []): array
    {
        $input = $request->all();
        $size = (int) DB::table('staff_preferences')->where('user_id', Context::from($request)->user->id)->value('page_size');
        $filters = ['q' => '', 'page' => 1, 'per_page' => in_array($size, [10, 20, 50], true) ? $size : 20,
            'sort' => 'newest', ...array_fill_keys(array_keys($choices), '')];

        foreach (['q', 'page', 'per_page', 'sort', 'cursor', ...array_keys($choices)] as $key) {
            if (!isset($input[$key]) || $input[$key] === '') {
                continue;
            }
            $value = $input[$key];
            if (!is_scalar($value) || !mb_check_encoding((string) $value, 'UTF-8') || str_contains((string) $value, "\0")
                || mb_strlen((string) $value) > ($key === 'cursor' ? 1024 : 120)) {
                throw new StaffException('Choose a valid filter.', 422, $key);
            }
            if ($key === 'cursor') {
                $cursor = Cursor::fromEncoded((string) $value)?->toArray();
                if (!$cursor || count($cursor) !== 2 || !isset($cursor['id'])
                    || !preg_match('/^[1-9][0-9]{0,14}$/D', (string) $cursor['id']) || is_bool($cursor['id'])) {
                    throw new StaffException('Choose a valid page cursor.', 422, $key);
                }
            }
            if (in_array($key, ['page', 'per_page', 'owner_id', 'artist_id', 'assignee_id', 'period_id', 'invoice_id', 'subscription_id'], true)) {
                $pattern = in_array($key, ['page', 'per_page'], true) ? '/^[1-9][0-9]{0,5}$/D' : '/^[1-9][0-9]{0,14}$/D';
                if (!preg_match($pattern, (string) $value) || ($key === 'per_page' && (int) $value > 50)) {
                    throw new StaffException('Choose a valid page size or reference.', 422, $key);
                }
                $value = (int) $value;
            } elseif ($key === 'sort' && !in_array($value, ['newest', 'oldest'], true)) {
                throw new StaffException('Choose a valid sort order.', 422, $key);
            } elseif (isset($choices[$key]) && $choices[$key] && !in_array($value, $choices[$key], true)) {
                throw new StaffException('Choose a valid filter.', 422, $key);
            }
            $filters[$key] = is_string($value) ? trim($value) : $value;
        }

        return $filters;
    }
}
