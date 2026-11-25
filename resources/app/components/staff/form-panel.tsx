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
    <Card className="overflow-hidden border-border/80 shadow-card">
      <CardHeader className="border-b border-border/70 bg-muted/20 pb-4">
        <div>
          <CardTitle className="text-base font-semibold text-foreground">
            {title}
          </CardTitle>
          {description && (
            <CardDescription className="mt-0.5 text-xs text-muted-foreground">
              {description}
            </CardDescription>
          )}
        </div>
      </CardHeader>
      <form className="space-y-4 p-5 sm:p-6" onSubmit={onSubmit}>
        <FormErrors errors={errors} />
        <fieldset disabled={busy} className="space-y-4">
          {children}
          <div className="pt-2">
            <Button
              type="submit"
              disabled={busy || disabled}
              loading={busy}
              className="shadow-xs font-medium"
            >
              {submit}
            </Button>
          </div>
        </fieldset>
      </form>
    </Card>
  );
}
