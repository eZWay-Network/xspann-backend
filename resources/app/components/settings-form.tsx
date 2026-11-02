import type { FormEventHandler, ReactNode } from "react";
import { Card, CardContent, CardFooter } from "./ui/card";

export function SettingsForm({
  children,
  action,
  note,
  onSubmit,
}: {
  children: ReactNode;
  action?: ReactNode;
  note?: string;
  onSubmit: FormEventHandler<HTMLFormElement>;
}) {
  return (
    <Card>
      <form onSubmit={onSubmit}>
        <CardContent className="space-y-5 pt-5 sm:pt-6">{children}</CardContent>
        {action && (
          <CardFooter className="flex-wrap">
            <span className="min-w-0 flex-1 basis-48 text-xs leading-5 text-muted-foreground">
              {note}
            </span>
            {action}
          </CardFooter>
        )}
      </form>
    </Card>
  );
}
