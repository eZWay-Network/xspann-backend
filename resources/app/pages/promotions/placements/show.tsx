import { useForm } from "@inertiajs/react";
import {
  DetailScreen,
  DetailFields,
  RelatedLinks,
  type RecordLink,
} from "@/components/staff/detail";
import { FormPanel } from "@/components/staff/form-panel";
import { ReasonField } from "@/components/staff/review-forms";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { DateTime } from "@/components/ui/date-time";
import { Alert } from "@/components/ui/alert";
import type { Placement } from "@/types/promotions";
export default function PlacementDetail({
  placement,
  counterparts,
  edit_url,
  evidence_url,
  conflict,
}: {
  placement: Placement;
  counterparts: RecordLink[];
  edit_url: string | null;
  evidence_url: string | null;
  conflict: string | null;
}) {
  return (
    <DetailScreen
      fields={[
        { label: "Type", value: placement.type },
        { label: "Status", value: placement.status },
        { label: "Artist ID", value: placement.artist_id },
        { label: "Month", value: placement.month },
        { label: "Destination", value: placement.destination },
        { label: "Version", value: placement.version },
        { label: "Scheduled start", value: placement.starts_at },
        { label: "Scheduled end", value: placement.ends_at },
        { label: "Due", value: placement.due_at },
        { label: "Reason", value: placement.reason },
      ]}
    >
      {conflict && <Alert>{conflict}</Alert>}
      <RelatedLinks links={counterparts} />
      <DetailFields
        title="Replacement consent"
        fields={[
          { label: "Owner consent", value: placement.extension.consent },
          { label: "Proposed start", value: placement.extension.starts_at },
          { label: "Proposed end", value: placement.extension.ends_at },
          { label: "Owner responded", value: placement.extension.consented_at },
        ]}
      />
      {placement.evidence && (
        <DetailFields
          title="Recorded delivery evidence"
          fields={[
            { label: "Proof reference", value: placement.evidence.proof },
            {
              label: "Actual start",
              value: placement.evidence.actual_starts_at,
            },
            { label: "Actual end", value: placement.evidence.actual_ends_at },
            { label: "Note", value: placement.evidence.note },
          ]}
        />
      )}
      <div className="grid items-start gap-6 lg:grid-cols-2">
        {edit_url && !["completed", "cancelled"].includes(placement.status) && (
          <Edit key={placement.version} url={edit_url} placement={placement} />
        )}
        {evidence_url && !placement.evidence && (
          <Evidence
            key={placement.version}
            url={evidence_url}
            placement={placement}
          />
        )}
      </div>
    </DetailScreen>
  );
}
function Edit({ url, placement }: { url: string; placement: Placement }) {
  const form = useForm({
    version: placement.version,
    action: "schedule",
    starts_at: "",
    ends_at: "",
    reason: "",
  });
  return (
    <FormPanel
      title="Schedule or update"
      description="Moving an interrupted or started obligation proposes dates for owner acceptance."
      busy={form.processing}
      errors={form.errors}
      submit="Update placement"
      onSubmit={(event) => {
        event.preventDefault();
        form.transform((data) => ({
          version: placement.version,
          action: data.action,
          reason: data.reason,
          ...(data.action === "schedule"
            ? { starts_at: data.starts_at, ends_at: data.ends_at }
            : {}),
        }));
        form.patch(url, { preserveScroll: true });
      }}
    >
      <Field label="Action">
        <Select
          label="Placement action"
          value={form.data.action}
          options={["schedule", "interrupt", "cancel"].map((value) => ({
            value,
            label: value,
          }))}
          onValueChange={(value) => form.setData("action", value)}
        />
      </Field>
      {form.data.action === "schedule" && (
        <>
          <Field label="Start (UTC)">
            <DateTime
              label="Schedule start"
              value={form.data.starts_at}
              onChange={(value) => form.setData("starts_at", value)}
            />
          </Field>
          <Field label="End (UTC)">
            <DateTime
              label="Schedule end"
              value={form.data.ends_at}
              onChange={(value) => form.setData("ends_at", value)}
            />
          </Field>
        </>
      )}
      <ReasonField
        value={form.data.reason}
        onChange={(value) => form.setData("reason", value)}
      />
    </FormPanel>
  );
}
function Evidence({ url, placement }: { url: string; placement: Placement }) {
  const form = useForm({
    version: placement.version,
    actual_starts_at: "",
    actual_ends_at: "",
    proof: "",
    note: "",
  });
  return (
    <FormPanel
      title="Record actual delivery"
      description="Past delivery only, within the approved interval. This completes one obligation."
      busy={form.processing}
      errors={form.errors}
      submit="Record evidence"
      onSubmit={(event) => {
        event.preventDefault();
        form.post(url, { preserveScroll: true });
      }}
    >
      <Field label="Actual start (UTC)">
        <DateTime
          label="Actual start"
          value={form.data.actual_starts_at}
          onChange={(value) => form.setData("actual_starts_at", value)}
        />
      </Field>
      <Field label="Actual end (UTC)">
        <DateTime
          label="Actual end"
          value={form.data.actual_ends_at}
          onChange={(value) => form.setData("actual_ends_at", value)}
        />
      </Field>
      <Field label="Approved HTTPS proof or evidence: archive reference">
        <Input
          value={form.data.proof}
          required
          maxLength={2000}
          onChange={(event) => form.setData("proof", event.target.value)}
        />
      </Field>
      <ReasonField
        label="Evidence note"
        value={form.data.note}
        onChange={(value) => form.setData("note", value)}
      />
    </FormPanel>
  );
}
