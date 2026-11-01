import {
  RecordsScreen,
  type RecordsProps,
} from "@/components/staff/records-screen";
import {
  RecoveryDialog,
  type RecoveryAction,
} from "@/components/staff/recovery-form";
import { Card } from "@/components/ui/card";
import { useApp } from "@/contexts/app";
export default function Communications(
  props: RecordsProps & {
    delivery: { pending: number; oldest_at: string | null };
    outbox: { pending: number; oldest_at: string | null };
    quota: { announcements: number; recipients: number };
    realtime_action: (RecoveryAction & { key: string }) | null;
  },
) {
  const { formatDateTime } = useApp();
  return (
    <RecordsScreen {...props}>
      <Card className="space-y-3 p-5">
        <h2 className="font-semibold">Announcement delivery</h2>
        <p className="text-sm text-muted-foreground">
          {props.delivery.pending} pending recipients · Oldest acceptance:{" "}
          {props.delivery.oldest_at
            ? formatDateTime(props.delivery.oldest_at)
            : props.delivery.pending > 0
              ? "Unknown"
              : "none"}
        </p>
        <p className="text-xs text-muted-foreground">
          Backlog age starts when delivery is accepted, independently of when
          the draft was created.
        </p>
      </Card>
      <Card className="space-y-3 p-5">
        <h2 className="font-semibold">Realtime outbox</h2>
        <p className="text-sm text-muted-foreground">
          {props.outbox.pending} pending hints · Oldest:{" "}
          {props.outbox.oldest_at
            ? formatDateTime(props.outbox.oldest_at)
            : "none"}
        </p>
        <p className="text-sm text-muted-foreground">
          Pro allowance: {props.quota.announcements} announcements per paid
          period, up to {props.quota.recipients} recipients each.
        </p>
        {props.realtime_action && (
          <RecoveryDialog action={props.realtime_action} />
        )}
      </Card>
    </RecordsScreen>
  );
}
