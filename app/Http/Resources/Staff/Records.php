<?php

namespace App\Http\Resources\Staff;

/** Shared presentation only. Each controller supplies its authorized query and projection. */
final class Records
{
    public static function listing($query, array $filters, callable $project, string $route, array $choices = [], bool $cursor = false): array
    {
        $query->orderBy('id', $filters['sort'] === 'oldest' ? 'asc' : 'desc');
        $page = $cursor ? $query->cursorPaginate($filters['per_page']) : $query->paginate($filters['per_page'], 'page');

        return [
            'rows' => array_map($project, $page->items()),
            'pagination' => $cursor
                ? ['kind' => 'cursor', 'next_url' => $page->nextPageUrl(), 'previous_url' => $page->previousPageUrl()]
                : ['kind' => 'page', ...$page->paginationMeta()],
            'filters' => $filters,
            'filter_options' => array_map(fn ($values) => array_map(fn ($value) => ['value' => $value, 'label' => self::label($value)], $values), $choices),
            'filter_fields' => array_values(array_intersect(['owner_id', 'artist_id', 'assignee_id', 'period_id'], array_keys($filters))),
            'list_url' => (string) route($route),
        ];
    }

    public static function row(object $row, string $title, ?string $url, string $status, ?string $description = null, ?string $timezone = null): array
    {
        return ['id' => (int) $row->id, 'title' => $title, 'url' => $url, 'status' => $status,
            'description' => $description, 'created_at' => self::date($row->created_at ?? null, $timezone)];
    }

    public static function fields(object $row, array $columns, ?string $timezone = null): array
    {
        $fields = [];
        foreach ($columns as $column) {
            $value = $row->$column ?? null;
            $fields[] = ['label' => self::label($column), 'value' => str_ends_with($column, '_at') ? self::date($value, $timezone) : $value];
        }

        return $fields;
    }

    public static function label(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }

    public static function date(?string $value, ?string $timezone = null): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable($value, new \DateTimeZone($timezone ?? config('app.timezone', 'UTC'))))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
