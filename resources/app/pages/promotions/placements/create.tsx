import type { AppPageProps } from "@/types";
import { useForm, usePage } from "@inertiajs/react";
import { PageHeader } from "@/components/page-header";
import { FormPanel } from "@/components/staff/form-panel";
import { ReasonField } from "@/components/staff/review-forms";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";
import { DatePicker } from "@/components/ui/date-picker";
import { Alert } from "@/components/ui/alert";
export default function CreatePlacement({
  store_url,
  network_accounts,
  capacity,
}: {
  store_url: string;
  network_accounts: { value: string; label: string }[];
  capacity: number;
}) {
  const { page } = usePage<AppPageProps>().props;
  const form = useForm({
    artist_id: "",
    month: "",
    type: "feature",
    network_account: network_accounts[0]?.value ?? "",
    capacity_confirmed: false,
    reason: "",
  });
  const unavailable = capacity <= 0 || network_accounts.length === 0;
  return (
    <div className="max-w-3xl space-y-6">
      <PageHeader
        title={page.title}
        description="One enrollment creates both the seven-day feature and the named network-post obligation."
      />
      {unavailable && (
        <Alert>
          Monthly pilot or named network capacity is not configured.
        </Alert>
      )}
      <FormPanel
        title="Monthly enrollment"
        busy={form.processing}
        errors={form.errors}
        submit="Create both obligations"
        disabled={unavailable}
        onSubmit={(event) => {
          event.preventDefault();
          form.post(store_url);
        }}
      >
        <Field label="Eligible artist ID">
          <Input
            type="number"
            min={1}
            required
            value={form.data.artist_id}
            onChange={(event) => form.setData("artist_id", event.target.value)}
          />
        </Field>
        <Field
          label="Enrollment month (UTC)"
          description="Choose any day in the intended month."
        >
          <DatePicker
            label="Enrollment month"
            value={form.data.month ? `${form.data.month}-01` : ""}
            required
            onChange={(value) => form.setData("month", value.slice(0, 7))}
          />
        </Field>
        <Field label="Named network account">
          <Select
            label="Named network account"
            value={form.data.network_account}
            options={network_accounts}
            onValueChange={(value) => form.setData("network_account", value)}
          />
        </Field>
        <label className="flex items-start gap-3 text-sm">
          <Checkbox
            checked={form.data.capacity_confirmed}
            onCheckedChange={(value) =>
              form.setData("capacity_confirmed", Boolean(value))
            }
          />
          I confirm actual capacity for both monthly obligations.
        </label>
        <ReasonField
          value={form.data.reason}
          onChange={(value) => form.setData("reason", value)}
        />
      </FormPanel>
    </div>
  );
}
