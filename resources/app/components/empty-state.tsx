import type { ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
export function EmptyState({
  icon: Icon,
  title,
  description,
  action,
}: {
  icon: LucideIcon;
  title: string;
  description: string;
  action?: ReactNode;
}) {
  return (
    <div className="flex min-h-72 flex-col items-center justify-center px-6 py-12 text-center">
      <span className="mb-5 flex size-12 items-center justify-center rounded-xl border bg-muted/40 text-muted-foreground">
        <Icon className="size-6" strokeWidth={1.5} />
      </span>
      <h2 className="text-base font-semibold">{title}</h2>
      <p className="mt-2 max-w-sm text-description">{description}</p>
      {action && <div className="mt-5">{action}</div>}
    </div>
  );
}
