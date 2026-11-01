import { router, useForm } from "@inertiajs/react";
import { useState } from "react";
import { DetailScreen, DetailFields } from "@/components/staff/detail";
import { FormPanel } from "@/components/staff/form-panel";
import { ReasonField } from "@/components/staff/review-forms";
import { Field } from "@/components/ui/field";
import { Select } from "@/components/ui/select";
import { DateTime } from "@/components/ui/date-time";
import { Alert } from "@/components/ui/alert";
import { Card } from "@/components/ui/card";
import type { Boost, Placement } from "@/types/promotions";
import { CursorPagination } from "@/components/records-pagination";
import { Table, TableCell, TableHead } from "@/components/ui/table";
import { useApp } from "@/contexts/app";
const money = (value: number) =>
  new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(
    value / 100,
  );
type Props = {
  boost: Boost;
  placement: Placement | null;
  creative: { caption: string; preview_url: string } | null;
  remedy_preview_cents: number;
  remedy_options: string[];
  delivery: {
    rows: { id: number; starts_at: string; ends_at: string }[];
    next_url: string | null;
    previous_url: string | null;
  };
  decision_options: string[];
  decision_url: string | null;
  remedy_url: string | null;
  conflict: string | null;
  idempotency_key: string;
};
export default function BoostDetail({
  boost,
  placement,
  creative,
  remedy_preview_cents,
  remedy_options,
  delivery,
  decision_options,
  decision_url,
  remedy_url,
  conflict,
  idempotency_key,
}: Props) {
  const { formatDateTime, preferences } = useApp();
  const [busy, setBusy] = useState(false);
  return (
    <DetailScreen
      fields={[
        { label: "Status", value: boost.status },
        { label: "Version", value: boost.version },
        { label: "Artist", value: boost.artist_id },
        { label: "Surface", value: boost.surface },
        { label: "Start", value: boost.starts_at },
        { label: "End", value: boost.ends_at },
        { label: "Advertising declaration", value: boost.rights_version },
        { label: "Declared at", value: boost.rights_confirmed_at },
        { label: "Spending hold", value: boost.staff_hold },
        { label: "Stop reason", value: boost.stop_reason },
        { label: "Settlement due", value: boost.settle_after },
        { label: "Settled at", value: boost.settled_at },
      ]}
    >
      {conflict && <Alert>{conflict}</Alert>}
      <DetailFields
        title="Delivery and credit"
        fields={[
          { label: "Reserved", value: money(boost.report.reserved_cents) },
          { label: "Charged", value: money(boost.report.charged_cents) },
          { label: "Returned", value: money(boost.report.returned_cents) },
          {
            label: "Additional unused-time credit preview",
            value: money(remedy_preview_cents),
          },
          {
            label: "Recorded eligible seconds",
            value: boost.report.delivered_seconds,
          },
          {
            label: "Undelivered seconds",
            value: boost.report.undelivered_seconds,
          },
          {
            label: "Informational render events",
            value: boost.report.render_events,
          },
          {
            label: "Informational click events",
            value: boost.report.click_events,
          },
        ]}
      />
      <p className="text-xs text-muted-foreground">
        Events are not billed impressions or guaranteed reach. Credit is
        recalculated against current delivery evidence when the remedy runs.
      </p>
      <Card className="overflow-hidden">
        <div className="space-y-1 p-5">
          <h2 className="font-semibold">Recorded delivery intervals</h2>
          <p className="text-xs text-muted-foreground">
            Historical observations. The eligible total above excludes outages
            and eligibility cutoffs. Times in {preferences.timezone}.
          </p>
        </div>
        <div aria-busy={busy}>
          {delivery.rows.length ? (
            <Table>
              <thead>
                <tr>
                  <TableHead>Start</TableHead>
                  <TableHead>End</TableHead>
                </tr>
              </thead>
              <tbody>
                {delivery.rows.map((interval) => (
                  <tr key={interval.id}>
                    <TableCell>
                      {formatDateTime(interval.starts_at, true)}
                    </TableCell>
                    <TableCell>
                      {formatDateTime(interval.ends_at, true)}
                    </TableCell>
                  </tr>
                ))}
              </tbody>
            </Table>
          ) : (
            <p className="px-5 pb-5 text-sm text-muted-foreground">
              No delivery intervals recorded.
            </p>
          )}
        </div>
        <div className="p-5">
          <CursorPagination
            previous={delivery.previous_url}
            next={delivery.next_url}
            busy={busy}
            onNavigate={(url) =>
              router.get(
                url,
                {},
                {
                  preserveScroll: true,
                  onStart: () => setBusy(true),
                  onFinish: () => setBusy(false),
                },
              )
            }
          />
        </div>
      </Card>
      {creative ? (
        <Card className="space-y-3 p-5">
          <h2 className="font-semibold">Eligible public creative</h2>
          <p className="whitespace-pre-wrap text-sm">{creative.caption}</p>
          <a
            className="text-sm text-primary underline"
            href={creative.preview_url}
            target="_blank"
            rel="noopener noreferrer"
          >
            Open public creative
          </a>
        </Card>
      ) : (
        <Alert>Public creative is currently unavailable or ineligible.</Alert>
      )}
      {placement && (
        <DetailFields
          title="Owner extension consent"
          fields={[
            { label: "Consent", value: placement.extension.consent },
            { label: "Placement version", value: placement.version },
            { label: "Proposed start", value: placement.extension.starts_at },
            { label: "Proposed end", value: placement.extension.ends_at },
            {
              label: "Owner responded at",
              value: placement.extension.consented_at,
            },
          ]}
        />
      )}
      <div className="grid items-start gap-6 lg:grid-cols-2">
        {decision_url && (
          <BoostDecision
            key={boost.version}
            boost={boost}
            url={decision_url}
            options={decision_options}
          />
        )}
        {remedy_url && boost.status === "interrupted" && (
          <Remedy
            key={`${boost.version}:${placement?.version}`}
            boost={boost}
            placement={placement}
            url={remedy_url}
            options={remedy_options}
            idempotencyKey={idempotency_key}
          />
        )}
      </div>
    </DetailScreen>
  );
}
function BoostDecision({
  boost,
  url,
  options,
}: {
  boost: Boost;
  url: string;
  options: string[];
}) {
  const form = useForm({
    version: boost.version,
    decision: options[0],
    reason: "",
  });
  return (
    <FormPanel
      title="Review campaign"
      busy={form.processing}
      errors={form.errors}
      submit="Confirm decision"
      onSubmit={(event) => {
        event.preventDefault();
        form.post(url, { preserveScroll: true });
      }}
    >
      <Field label="Decision">
        <Select
          label="Campaign decision"
          value={form.data.decision}
          options={options.map((value) => ({
            value,
            label: value.replaceAll("_", " "),
          }))}
          onValueChange={(value) => form.setData("decision", value)}
        />
      </Field>
      <ReasonField
        value={form.data.reason}
        onChange={(value) => form.setData("reason", value)}
      />
    </FormPanel>
  );
}
function Remedy({
  boost,
  placement,
  url,
  idempotencyKey,
  options,
}: {
  boost: Boost;
  placement: Placement | null;
  url: string;
  idempotencyKey: string;
  options: string[];
}) {
  const form = useForm({
    action: options[0],
    version: boost.version,
    reason: "",
    starts_at: "",
  });
  return (
    <FormPanel
      title="Resolve unused delivery"
      description="An extension requires the owner's acceptance of the exact proposed dates."
      busy={form.processing}
      errors={form.errors}
      submit="Apply remedy"
      disabled={
        form.data.action === "extend" &&
        placement?.extension.consent !== "accepted"
      }
      onSubmit={(event) => {
        event.preventDefault();
        form.transform((data) => ({
          action: data.action,
          version: boost.version,
          reason: data.reason,
          ...(data.action === "propose_extension"
            ? { starts_at: data.starts_at }
            : {}),
          ...(data.action === "extend"
            ? { placement_version: placement?.version }
            : {}),
        }));
        form.post(url, {
          headers: { "Idempotency-Key": idempotencyKey },
          preserveScroll: true,
        });
      }}
    >
      <Field label="Remedy">
        <Select
          label="Remedy"
          value={form.data.action}
          options={[
            { value: "credit", label: "Return unused credit" },
            { value: "propose_extension", label: "Propose extension" },
            { value: "extend", label: "Apply accepted extension" },
          ].filter((option) => options.includes(option.value))}
          onValueChange={(value) => form.setData("action", value)}
        />
      </Field>
      {form.data.action === "propose_extension" && (
        <Field label="Proposed start (UTC)">
          <DateTime
            label="Proposed start"
            value={form.data.starts_at}
            onChange={(value) => form.setData("starts_at", value)}
          />
        </Field>
      )}
      <ReasonField
        value={form.data.reason}
        onChange={(value) => form.setData("reason", value)}
      />
    </FormPanel>
  );
}
