import { Link } from "@inertiajs/react";
import { DetailScreen, type DetailField } from "@/components/staff/detail";
import { DecisionForm } from "@/components/staff/review-forms";
import { Alert } from "@/components/ui/alert";
type Props = {
  confirmation_url: string;
  record: {
    id: number;
    version: number;
    status: string;
    fields: DetailField[];
  };
  decision: { action: string; reason: string; target_type: string };
  report_url: string | null;
  decision_url: string | null;
  conflict: string | null;
};
export default function Appeal({
  record,
  confirmation_url,
  decision,
  report_url,
  decision_url,
  conflict,
}: Props) {
  return (
    <DetailScreen
      fields={[
        ...record.fields,
        { label: "Original decision", value: decision.action },
        { label: "Original reason", value: decision.reason },
      ]}
      links={
        report_url
          ? [{ label: "Open original case and evidence", url: report_url }]
          : []
      }
    >
      {conflict && <Alert>{conflict}</Alert>}
      {decision_url &&
        decision.target_type === "user" &&
        decision.action === "soft_delete" && (
          <Link className="text-sm underline" href={confirmation_url}>
            Confirm password for account deletion restoration
          </Link>
        )}
      {decision_url && (
        <DecisionForm
          url={decision_url}
          version={record.version}
          status={record.status}
          appeal
        />
      )}
    </DetailScreen>
  );
}
