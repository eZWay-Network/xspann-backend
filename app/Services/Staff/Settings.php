<?php

namespace App\Services\Staff;

use App\Services\AuditTrail;
use Spark\Facades\DB;

final class Settings
{
    public const DEFAULTS = ['workspace_name' => 'XPN Social staff', 'support_url' => ''];

    public static function read(): array
    {
        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $row = DB::table('staff_settings')->where('key', $key)->first();
            $values[$key] = ['value' => $row?->value ?? $default, 'version' => (int) ($row?->version ?? 0)];
        }

        return $values;
    }

    public static function workspace(): array
    {
        $settings = self::read();

        return ['name' => $settings['workspace_name']['value'], 'support_url' => $settings['support_url']['value'] ?: null];
    }

    public static function save(Context $context, array $input): void
    {
        Team::locked(function () use ($context, $input): void {
            Sessions::requireCurrent($context)->authorize('settings.manage');
            $key = $input['key'];
            abort_unless(array_key_exists($key, self::DEFAULTS), 422);
            $value = trim($input['value'] ?? '');
            abort_unless($key !== 'workspace_name' || mb_strlen($value) >= 3, 422, 'Enter a workspace name.');
            if ($key === 'support_url' && $value !== '') {
                abort_unless(filter_var($value, FILTER_VALIDATE_URL) && parse_url($value, PHP_URL_SCHEME) === 'https'
                    && !parse_url($value, PHP_URL_USER) && !parse_url($value, PHP_URL_PASS), 422, 'Use an HTTPS support destination.');
            }
            $row = DB::table('staff_settings')->where('key', $key)->lockForUpdate()->first();
            abort_unless((int) ($row?->version ?? 0) === (int) $input['version'], 409, 'This setting changed. Reload before saving.');
            DB::table('staff_settings')->upsert([
                'key' => $key, 'value' => $value, 'version' => (int) $input['version'] + 1,
                'updated_by' => $context->user->id, 'created_at' => Sessions::timestamp(), 'updated_at' => Sessions::timestamp(),
            ], ['key'], ['value', 'version', 'updated_by', 'updated_at']);
            AuditTrail::record('staff.setting.updated', ['key' => $key, 'before' => $row?->value ?? self::DEFAULTS[$key], 'after' => $value], $context->user->id, 'staff');
        });
    }
}
