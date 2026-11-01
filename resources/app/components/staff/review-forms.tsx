import { useForm } from "@inertiajs/react";
import { Field } from "@/components/ui/field";
import { Textarea } from "@/components/ui/textarea";
import { Select } from "@/components/ui/select";
import { FormPanel } from "@/components/staff/form-panel";
import type { CaseTarget } from "@/components/staff/detail";
export function ReasonField({
  value,
  onChange,
  label = "Reason",
  maxLength = 1000,
}: {
  value: string;
  onChange: (value: string) => void;
  label?: string;
  maxLength?: number;
}) {
  return (
    <Field label={label}>
      <Textarea
        required
        minLength={5}
        maxLength={maxLength}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        rows={3}
      />
    </Field>
  );
}
export function CaseForm({ target }: { target: CaseTarget }) {
  const form = useForm({
    target_type: target.type,
    target_id: target.id,
    reason: "",
    details: "",
  });
  return (
    <FormPanel
      title="Create review case"
      description="Public account or content only. A different staff member must review your case."
      busy={form.processing}
      errors={form.errors}
      submit="Create case"
      onSubmit={(event) => {
        event.preventDefault();
        form.post(target.url, { preserveScroll: true });
      }}
    >
      <ReasonField
        maxLength={255}
        value={form.data.reason}
        onChange={(value) => form.setData("reason", value)}
      />
      <Field label="Additional context (optional)">
        <Textarea
          maxLength={2000}
          value={form.data.details}
          onChange={(event) => form.setData("details", event.target.value)}
        />
      </Field>
    </FormPanel>
  );
}
export function DecisionForm({
  url,
  version,
  status,
  appeal = false,
}: {
  url: string;
  version: number;
  status: string;
  appeal?: boolean;
}) {
  const form = useForm({
    action: status === "open" ? "dismiss" : "restore",
    outcome: "uphold",
    version,
    reason: "",
  });
  const choices = appeal
    ? ["uphold", "overturn"]
    : status === "open"
      ? ["dismiss", "restrict"]
      : ["restore"];
  const key = appeal ? "outcome" : "action";
  return (
    <FormPanel
      title={appeal ? "Resolve appeal" : "Record decision"}
      description="The reason is shared with the affected account. The current version is checked before saving."
      busy={form.processing}
      errors={form.errors}
      submit="Confirm decision"
      onSubmit={(event) => {
        event.preventDefault();
        form.transform((data) => ({
          [key]: data[key],
          version,
          reason: data.reason,
        }));
        form.post(url, {
          preserveScroll: true,
          onSuccess: () => form.reset("reason"),
        });
      }}
    >
      <Field label="Decision">
        <Select
          label="Decision"
          value={form.data[key]}
          options={choices.map((value) => ({
            value,
            label: value.replaceAll("_", " "),
          }))}
          onValueChange={(value) => form.setData(key, value)}
        />
      </Field>
      <ReasonField
        value={form.data.reason}
        onChange={(value) => form.setData("reason", value)}
      />
    </FormPanel>
  );
}
export type Workflow = {
  version: number;
  assignee_id: string;
  priority: string;
  tag: string;
};
export function AssignmentForm({
  url,
  workflow,
  reviewers,
}: {
  url: string;
  workflow: Workflow;
  reviewers: { value: string; label: string }[];
}) {
  const form = useForm(workflow);
  return (
    <FormPanel
      title="Case triage"
      busy={form.processing}
      errors={form.errors}
      submit="Update assignment"
      onSubmit={(event) => {
        event.preventDefault();
        form.transform((data) => ({
          ...data,
          version: workflow.version,
          assignee_id: data.assignee_id || null,
          tag: data.tag || null,
        }));
        form.patch(url, { preserveScroll: true });
      }}
    >
      <Field label="Reviewer">
        <Select
          label="Reviewer"
          value={form.data.assignee_id}
          options={[{ value: "", label: "Unassigned" }, ...reviewers]}
          onValueChange={(value) => form.setData("assignee_id", value)}
        />
      </Field>
      <Field label="Priority">
        <Select
          label="Priority"
          value={form.data.priority}
          options={["low", "normal", "high", "urgent"].map((value) => ({
            value,
            label: value,
          }))}
          onValueChange={(value) => form.setData("priority", value)}
        />
      </Field>
      <Field label="Reason tag">
        <Select
          label="Reason tag"
          value={form.data.tag}
          options={[
            { value: "", label: "No tag" },
            ...["rights", "safety", "spam", "other"].map((value) => ({
              value,
              label: value,
            })),
          ]}
          onValueChange={(value) => form.setData("tag", value)}
        />
      </Field>
    </FormPanel>
  );
}
export function NoteForm({ url }: { url: string }) {
  const form = useForm({ body: "" });
  return (
    <FormPanel
      title="Add internal note"
      description="Append-only. This note is not sent to the customer."
      busy={form.processing}
      errors={form.errors}
      submit="Add note"
      onSubmit={(event) => {
        event.preventDefault();
        form.post(url, { preserveScroll: true, onSuccess: () => form.reset() });
      }}
    >
      <Field label="Note">
        <Textarea
          required
          minLength={3}
          maxLength={4000}
          value={form.data.body}
          onChange={(event) => form.setData("body", event.target.value)}
          rows={4}
        />
      </Field>
    </FormPanel>
  );
}
