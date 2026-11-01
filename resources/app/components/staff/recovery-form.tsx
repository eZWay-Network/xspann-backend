import { useState } from "react";
import { useForm } from "@inertiajs/react";
import { FormPanel } from "@/components/staff/form-panel";
import { ReasonField } from "@/components/staff/review-forms";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogTitle,
  DialogDescription,
  DialogDismiss,
} from "@/components/ui/dialog";
export type RecoveryAction = { label: string; url: string; key?: string };
export function RecoveryForm({
  action,
  idempotencyKey,
}: {
  action: RecoveryAction;
  idempotencyKey: string;
}) {
  const form = useForm({ reason: "", idempotency_key: idempotencyKey });
  return (
    <FormPanel
      title={action.label}
      description="Describe the recovery reason. The worker checks current eligibility and records the outcome before this action is complete."
      busy={form.processing}
      errors={form.errors}
      submit="Queue recovery"
      onSubmit={(event) => {
        event.preventDefault();
        form.post(action.url);
      }}
    >
      <ReasonField
        value={form.data.reason}
        onChange={(value) => form.setData("reason", value)}
      />
    </FormPanel>
  );
}
export function RecoveryDialog({
  action,
}: {
  action: RecoveryAction & { key: string };
}) {
  const [open, setOpen] = useState(false);
  return (
    <>
      <Button variant="secondary" size="sm" onClick={() => setOpen(true)}>
        {action.label}
      </Button>
      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogDismiss />
          <DialogTitle>Confirm recovery</DialogTitle>
          <DialogDescription className="mb-5">
            This queues one targeted recovery. Review its recorded outcome
            afterward.
          </DialogDescription>
          {open && <RecoveryForm action={action} idempotencyKey={action.key} />}
        </DialogContent>
      </Dialog>
    </>
  );
}
