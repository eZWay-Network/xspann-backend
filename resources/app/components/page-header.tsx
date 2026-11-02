import type { ReactNode } from "react";
export function PageHeader({
  title,
  heading: Heading = "h1",
  description,
  actions,
}: {
  heading?: "h1" | "h2";
  title: string;
  description: string;
  actions?: ReactNode;
}) {
  return (
    <header className="flex flex-wrap items-start justify-between gap-4">
      <div>
        <Heading className="text-2xl font-semibold tracking-tight sm:text-[26px]">
          {title}
        </Heading>
        <p className="mt-1.5 text-description">{description}</p>
      </div>
      {actions && (
        <div className="flex flex-wrap items-center gap-2 pt-0.5">
          {actions}
        </div>
      )}
    </header>
  );
}
