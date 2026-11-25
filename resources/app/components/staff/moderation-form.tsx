import { Link, useForm } from "@inertiajs/react";
import { useState } from "react";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Button, buttonVariants } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogTitle,
  DialogDescription,
  DialogDismiss,
} from "@/components/ui/dialog";
import { FormPanel } from "@/components/staff/form-panel";
import { ReasonCode, ReasonField } from "@/components/staff/review-forms";

type Choice = { value: string; label: string };
export type ModerationProps = {
  url: string;
  type: string;
  target_label: string;
  target_version: number;
  author_version: number;
  choices: Choice[];
  author_choices: Choice[];
  restorations: (Choice & { version: number; confirmation: boolean })[];
  confirmation_url: string;
};

export function ModerationForm({
  moderation,
  report,
}: {
  moderation: ModerationProps;
  report?: { version: number; status: string };
}) {
  const [confirming, setConfirming] = useState(false);
  const choices = [
    ...moderation.choices,
    ...moderation.author_choices.map((choice) => ({
      ...choice,
      label: `${choice.label} · author`,
    })),
    ...moderation.restorations,
    ...(report && ["open", "reviewing"].includes(report.status)
      ? [
          { value: "start_review", label: "Start / continue review" },
          { value: "resolve", label: "Mark resolved" },
          { value: "dismiss", label: "Dismiss report" },
        ]
      : []),
  ];
  const form = useForm({
    selection: "",
    reason_code: "",
    reason: "",
    expires_at: "",
  });
  const restoration = moderation.restorations.find(
    (choice) => choice.value === form.data.selection,
  );
  const needsPassword =
    restoration?.confirmation ||
    (moderation.type === "user" && form.data.selection === "soft_delete");
  const authorAction =
    moderation.type !== "user" &&
    moderation.author_choices.some(
      (choice) => choice.value === form.data.selection,
    );
  if (!choices.length) return null;
  const submit = () => {
    form.transform((data) => ({
      action: restoration ? "restore" : data.selection,
      reason_code: data.reason_code,
      reason: data.reason,
      target_version:
        restoration?.version ??
        (authorAction ? moderation.author_version : moderation.target_version),
      decision_id: restoration ? Number(restoration.value.split(":")[1]) : null,
      expires_at:
        data.selection === "suspend" && data.expires_at
          ? new Date(data.expires_at).toISOString()
          : null,
      ...(report ? { version: report.version } : {}),
    }));
    form.post(moderation.url, {
      preserveScroll: true,
      onSuccess: () => form.reset(),
      onFinish: () => setConfirming(false),
    });
  };
  return (
    <>
      <FormPanel
        title="Moderation actions"
        description={`Apply an action to ${moderation.target_label}. The explanation is shared with the affected account.`}
        errors={form.errors}
        busy={form.processing}
        submit="Review action"
        disabled={!form.data.selection || !form.data.reason_code}
        onSubmit={(event) => {
          event.preventDefault();
          setConfirming(true);
        }}
      >
        <Field label="Action">
          <Select
            label="Action"
            value={form.data.selection}
            options={[{ value: "", label: "Choose an action" }, ...choices]}
            onValueChange={(value) => form.setData("selection", value)}
          />
        </Field>
        <ReasonCode
          value={form.data.reason_code}
          onChange={(value) => form.setData("reason_code", value)}
        />
        <ReasonField
          label="Admin explanation"
          maxLength={2000}
          value={form.data.reason}
          onChange={(value) => form.setData("reason", value)}
        />
        {form.data.selection === "suspend" && (
          <Field
            label="Suspension expires"
            description="Enter the expiry in your browser’s local timezone."
          >
            <Input
              type="datetime-local"
              required
              value={form.data.expires_at}
              onValueChange={(value) => form.setData("expires_at", value)}
            />
          </Field>
        )}
        {needsPassword && (
          <p className="text-sm">
            A password confirmation within the last five minutes is required.{" "}
            <Link
              className={buttonVariants({ variant: "secondary", size: "sm" })}
              href={moderation.confirmation_url}
            >
              Confirm password
            </Link>
          </p>
        )}
        {moderation.type === "audio" && form.data.selection === "remove" && (
          <p className="text-sm text-muted-foreground">
            Withdrawal also hides dependent videos. Review the dependency links
            above before confirming.
          </p>
        )}
      </FormPanel>
      <Dialog
        open={confirming}
        onOpenChange={(open) => {
          if (!form.processing) setConfirming(open);
        }}
      >
        <DialogContent>
          <DialogTitle>Confirm moderation action</DialogTitle>
          <DialogDescription className="mt-2">
            {
              choices.find((choice) => choice.value === form.data.selection)
                ?.label
            }{" "}
            on {authorAction ? "the author of " : ""}
            {moderation.target_label}.
          </DialogDescription>
          <DialogDismiss />
          <p className="mt-4 whitespace-pre-wrap break-words text-sm">
            {form.data.reason}
          </p>
          <p className="mt-2 text-xs text-muted-foreground">
            This action is recorded in the audit log. Admin soft-deletion
            retains evidence and files.
          </p>
          <div className="mt-6 flex justify-end gap-3">
            <Button
              variant="secondary"
              disabled={form.processing}
              onClick={() => setConfirming(false)}
            >
              Cancel
            </Button>
            <Button variant="danger" loading={form.processing} onClick={submit}>
              Confirm action
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  );
}
