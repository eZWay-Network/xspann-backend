import { useForm } from "@inertiajs/react";
import {
  RecordsScreen,
  type RecordsProps,
} from "@/components/staff/records-screen";
import { DetailFields } from "@/components/staff/detail";
import { FormPanel } from "@/components/staff/form-panel";
import { ReasonField } from "@/components/staff/review-forms";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { DateTime } from "@/components/ui/date-time";
type Offer = {
  configured: boolean;
  enabled: boolean;
  capacity: number;
  price_cents: number;
  duration_seconds: number;
  surface: string;
};
export default function Surface(
  props: RecordsProps & {
    offer: Offer;
    lease_seconds: number;
    observation_url: string | null;
  },
) {
  return (
    <RecordsScreen {...props}>
      <DetailFields
        title="Current booking gate"
        fields={[
          { label: "Surface", value: props.offer.surface },
          { label: "Configured", value: props.offer.configured },
          { label: "Bookings available", value: props.offer.enabled },
          { label: "Simultaneous capacity", value: props.offer.capacity },
          { label: "Price in USD cents", value: props.offer.price_cents },
          {
            label: "Eligible duration (seconds)",
            value: props.offer.duration_seconds,
          },
          { label: "Observation lease (seconds)", value: props.lease_seconds },
        ]}
      />
      {props.observation_url && <Observation url={props.observation_url} />}
    </RecordsScreen>
  );
}
function Observation({ url }: { url: string }) {
  const form = useForm({
    available: false,
    reference: "",
    note: "",
    starts_at: "",
    ends_at: "",
  });
  return (
    <FormPanel
      title="Record observation"
      description="Positive observations attest an independent check now. Incident dates must describe a real past or present outage."
      busy={form.processing}
      errors={form.errors}
      submit="Record observation"
      onSubmit={(event) => {
        event.preventDefault();
        form.transform((data) => ({
          available: data.available,
          reference: data.reference,
          note: data.note,
          ...(!data.available && data.starts_at
            ? { starts_at: data.starts_at }
            : {}),
          ...(!data.available && data.ends_at ? { ends_at: data.ends_at } : {}),
        }));
        form.post(url, { preserveScroll: true, onSuccess: () => form.reset() });
      }}
    >
      <Field label="Availability">
        <Select
          label="Availability"
          value={form.data.available ? "yes" : "no"}
          options={[
            { value: "no", label: "Incident / unavailable" },
            { value: "yes", label: "Verified available now" },
          ]}
          onValueChange={(value) => form.setData("available", value === "yes")}
        />
      </Field>
      <Field label="Independent observation or incident reference">
        <Input
          required
          maxLength={200}
          value={form.data.reference}
          onChange={(event) => form.setData("reference", event.target.value)}
        />
      </Field>
      {!form.data.available && (
        <div className="grid gap-4 lg:grid-cols-2">
          <DateTime
            label="Incident start"
            required={false}
            value={form.data.starts_at}
            onChange={(value) => form.setData("starts_at", value)}
          />
          <DateTime
            label="Incident end"
            required={false}
            value={form.data.ends_at}
            onChange={(value) => form.setData("ends_at", value)}
          />
        </div>
      )}
      <ReasonField
        label="Observation note"
        value={form.data.note}
        onChange={(value) => form.setData("note", value)}
      />
    </FormPanel>
  );
}
