import type { ReactNode } from "react";
export function SettingsSection({
  title,
  description,
  children,
}: {
  title: string;
  description: string;
  children: ReactNode;
}) {
  return (
    <section className="grid gap-5 border-b pb-8 last:border-0 lg:grid-cols-[200px_minmax(0,1fr)] xl:grid-cols-[240px_minmax(0,1fr)]">
      <div className="pt-1">
        <h2 className="text-sm font-semibold">{title}</h2>
        <p className="mt-2 text-xs leading-5 text-muted-foreground">
          {description}
        </p>
      </div>
      <div className="min-w-0">{children}</div>
    </section>
  );
}
