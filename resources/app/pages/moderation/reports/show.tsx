import { DetailScreen, type DetailField } from "@/components/staff/detail";
import { Disclosure } from "@/components/staff/disclosure";
import {
  AssignmentForm,
  DecisionForm,
  NoteForm,
  type Workflow,
} from "@/components/staff/review-forms";
import { Card } from "@/components/ui/card";
import { Alert } from "@/components/ui/alert";
import { useApp } from "@/contexts/app";
type Props = {
  record: {
    id: number;
    version: number;
    status: string;
    fields: DetailField[];
    reason: string;
  };
  workflow: Workflow;
  reviewers: { value: string; label: string }[];
  actions: {
    evidence: string | null;
    decision: string | null;
    assignment: string | null;
    note: string | null;
  };
  notes: {
    id: number;
    actor_id: number | null;
    body: string;
    created_at: string;
  }[];
  decisions: {
    id: number;
    action: string;
    reason: string;
    active: boolean;
    created_at: string;
  }[];
  conflict: string | null;
  target_url: string | null;
};
export default function Report({
  record,
  workflow,
  reviewers,
  actions,
  notes,
  decisions,
  conflict,
  target_url,
}: Props) {
  const { formatDateTime } = useApp();
  return (
    <DetailScreen
      links={
        target_url
          ? [{ label: "Target metadata and dependencies", url: target_url }]
          : []
      }
      fields={[
        { label: "Report reason", value: record.reason },
        ...record.fields,
      ]}
    >
      {conflict && <Alert>{conflict}</Alert>}
      {actions.evidence && <Disclosure url={actions.evidence} />}
      {decisions.length > 0 && (
        <Card className="space-y-4 p-5">
          <h2 className="font-semibold">Decision history</h2>
          {decisions.map((decision) => (
            <div className="border-t pt-4" key={decision.id}>
              <p className="text-sm font-medium">
                {decision.action} ·{" "}
                {decision.active ? "Active restriction" : "Recorded"}
              </p>
              <p className="mt-1 whitespace-pre-wrap text-sm">
                {decision.reason}
              </p>
              <p className="mt-1 text-xs text-muted-foreground">
                {formatDateTime(decision.created_at)}
              </p>
            </div>
          ))}
        </Card>
      )}
      <div className="grid items-start gap-6 lg:grid-cols-2">
        {actions.assignment && (
          <AssignmentForm
            key={`${record.id}:${workflow.version}`}
            url={actions.assignment}
            workflow={workflow}
            reviewers={reviewers}
          />
        )}
        {actions.decision && (
          <DecisionForm
            key={`${record.id}:${record.version}`}
            url={actions.decision}
            version={record.version}
            status={record.status}
          />
        )}
      </div>
      {notes.length > 0 && (
        <Card className="space-y-4 p-5">
          <h2 className="font-semibold">Internal notes</h2>
          {notes.map((note) => (
            <div className="border-t pt-4" key={note.id}>
              <p className="whitespace-pre-wrap break-words text-sm">
                {note.body}
              </p>
              <p className="mt-2 text-xs text-muted-foreground">
                Staff #{note.actor_id ?? "removed"} ·{" "}
                {formatDateTime(note.created_at)}
              </p>
            </div>
          ))}
        </Card>
      )}
      {actions.note && <NoteForm url={actions.note} />}
    </DetailScreen>
  );
}
