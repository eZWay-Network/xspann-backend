<?php

namespace App\Http\Resources\Staff;

use App\Services\Moderation;
use App\Services\Staff\Context;
use Spark\Facades\DB;

final class ModerationActions
{
    public static function forTarget(Context $context, string $type, int $id, ?int $reportId = null): array
    {
        $target = Moderation::target($type, $id);
        $version = Moderation::versionColumn($type);
        $ownerId = Moderation::owner($type, $target);
        $choices = Moderation::actionChoices($context, $type, $target);
        $restorations = [];
        $query = DB::table('moderation_decisions')->where('active', true);
        if ($reportId) {
            $query->where('report_id', $reportId);
        } else {
            $query->where('target_type', $type)->where('target_id', $id);
        }
        foreach ($query->orderBy('id', 'desc')->get() as $decision) {
            $ability = $decision->target_type === 'user'
                ? match ($decision->action) { 'ban' => 'users.ban', 'soft_delete' => 'users.delete', default => 'users.suspend' }
                : 'content.moderate';
            if (!$context->can($ability) || $ownerId === $context->user->id
                || DB::table('moderation_appeals')->where('decision_id', $decision->id)->where('status', 'open')->exists()) {
                continue;
            }
            $record = DB::table(Moderation::TABLES[$decision->target_type])->where('id', $decision->target_id)->first();
            if (!$record) {
                continue;
            }
            $column = Moderation::versionColumn($decision->target_type);
            $restorations[] = ['value' => 'restore:' . $decision->id,
                'label' => ($decision->action === 'ban' ? 'Unban' : ($decision->action === 'suspend' ? 'End suspension' : 'Restore')) . ' · decision #' . $decision->id,
                'version' => (int) $record->$column, 'confirmation' => $decision->target_type === 'user' && $decision->action === 'soft_delete'];
        }
        $owner = Moderation::target('user', $ownerId);
        $authorChoices = $reportId && $type !== 'user' ? array_values(array_filter(Moderation::actionChoices($context, 'user', $owner),
            fn (array $choice) => in_array($choice['value'], ['warn', 'suspend', 'ban', 'revoke_sessions'], true))) : [];
        $route = $type === 'user' ? 'staff.users.moderation' : 'staff.content.moderation';
        return [
            'url' => $reportId ? (string) route('staff.reports.decision', ['report' => $reportId])
                : (string) route($route, $type === 'user' ? ['user' => $id] : ['type' => $type, 'target' => $id]),
            'type' => $type, 'target_label' => ucfirst($type) . ' #' . $id,
            'target_version' => (int) $target->$version, 'author_version' => (int) $owner->moderation_version,
            'choices' => $choices, 'author_choices' => $authorChoices, 'restorations' => $restorations,
            'confirmation_url' => (string) route('staff.challenge')->withQuery(['destination' => $type === 'user' ? '/users/' . $id : ($reportId ? '/moderation/reports/' . $reportId : '/content/' . ($type === 'audio' ? 'audios' : $type . 's') . '/' . $id)]),
        ];
    }
}
