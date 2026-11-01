import { Link, useForm } from "@inertiajs/react";
import { DetailScreen, type DetailProps } from "@/components/staff/detail";
import {
  RecoveryForm,
  type RecoveryAction,
} from "@/components/staff/recovery-form";
import { FormPanel } from "@/components/staff/form-panel";
import { ReasonField } from "@/components/staff/review-forms";
import { Select } from "@/components/ui/select";
import { Field } from "@/components/ui/field";
import { Alert } from "@/components/ui/alert";
import { buttonVariants } from "@/components/ui/button";
type Allocation = {
  url: string;
  version: string;
  choices: string[];
  confirmation_url: string;
  confirmed: boolean;
};
export type BillingDetailProps = DetailProps & {
  recovery: RecoveryAction | null;
  idempotency_key: string;
  allocation?: Allocation | null;
};
function AllocationForm({ allocation }: { allocation: Allocation }) {
  const form = useForm({
    allocation: allocation.choices[0],
    reason: "",
    version: allocation.version,
  });
  return (
    <FormPanel
      title="Allocate adjustment"
      description="Record the service effect of this existing provider adjustment. Full-period allocation can revoke paid access; releasing a dispute hold does not reverse a refund."
      busy={form.processing}
      errors={form.errors}
      submit="Confirm allocation"
      disabled={!allocation.confirmed}
      onSubmit={(event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, version: allocation.version }));
        form.post(allocation.url, {
          preserveScroll: true,
          onSuccess: () => form.reset("reason"),
        });
      }}
    >
      {!allocation.confirmed && (
        <Alert>
          Fresh credential and MFA confirmation is required.{" "}
          <Link
            className={buttonVariants({ variant: "secondary", size: "sm" })}
            href={allocation.confirmation_url}
          >
            Confirm identity
          </Link>
        </Alert>
      )}
      <Field label="Allocation">
        <Select
          label="Allocation"
          value={form.data.allocation}
          options={allocation.choices.map((value) => ({
            value,
            label: value.replaceAll("_", " "),
          }))}
          onValueChange={(value) => form.setData("allocation", value)}
        />
      </Field>
      <ReasonField
        label="Review reason (at least 10 characters)"
        value={form.data.reason}
        onChange={(value) => form.setData("reason", value)}
      />
    </FormPanel>
  );
}
export function BillingDetail(props: BillingDetailProps) {
  return (
    <DetailScreen {...props}>
      {props.recovery && (
        <RecoveryForm
          action={props.recovery}
          idempotencyKey={props.idempotency_key}
        />
      )}
      {props.allocation && <AllocationForm allocation={props.allocation} />}
    </DetailScreen>
  );
}
