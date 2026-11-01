import type { ReactNode, FormEvent } from "react";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import { FormErrors } from "@/components/form-errors";
import { Button } from "@/components/ui/button";
export function FormPanel({
  title,
  description,
  errors,
  busy,
  onSubmit,
  children,
  submit = "Save",
  disabled = false,
}: {
  title: string;
  description?: string;
  errors: Record<string, string>;
  busy: boolean;
  onSubmit: (event: FormEvent) => void;
  children: ReactNode;
  submit?: string;
  disabled?: boolean;
}) {
  return (
    <Card>
      <CardHeader>
        <div>
          <CardTitle>{title}</CardTitle>
          {description && <CardDescription>{description}</CardDescription>}
        </div>
      </CardHeader>
      <form className="space-y-4 p-5" onSubmit={onSubmit}>
        <FormErrors errors={errors} />
        <fieldset disabled={busy} className="space-y-4">
          {children}
          <Button type="submit" disabled={busy || disabled}>
            {busy ? "Saving…" : submit}
          </Button>
        </fieldset>
      </form>
    </Card>
  );
}
