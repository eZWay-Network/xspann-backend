import { DetailScreen, type DetailField } from "@/components/staff/detail";
import { DecisionForm } from "@/components/staff/review-forms";
import { Alert } from "@/components/ui/alert";
type Props = {
  record: {
    id: number;
    version: number;
    status: string;
    fields: DetailField[];
  };
  decision: { action: string; reason: string };
  report_url: string | null;
  decision_url: string | null;
  conflict: string | null;
};
export default function Appeal({
  record,
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
