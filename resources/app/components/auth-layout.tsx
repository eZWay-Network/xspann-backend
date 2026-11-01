import type { ReactNode } from "react";
import { ShieldCheck } from "lucide-react";
import Brand from "@/components/brand";

export function AuthLayout({
  title,
  description,
  children,
}: {
  title: string;
  description: string;
  children: ReactNode;
}) {
  return (
    <main className="grid min-h-dvh bg-card lg:grid-cols-2">
      <section className="flex flex-col px-6 py-8 sm:px-12 xl:px-24">
        <header className="flex items-center justify-between gap-4">
          <Brand />
          <span className="text-xs font-medium text-muted-foreground">
            Staff console
          </span>
        </header>
        <div className="mx-auto my-auto w-full max-w-sm py-14 sm:py-16">
          <h1 className="text-3xl font-semibold tracking-tight">{title}</h1>
          <p className="mt-3 mb-8 text-description">{description}</p>
          {children}
          <p className="mt-8 flex items-center justify-center gap-1.5 text-xs text-muted-foreground">
            <ShieldCheck className="size-3.5" aria-hidden="true" />
            Restricted to authorized team members
          </p>
        </div>
        <footer className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 text-xs leading-5 text-muted-foreground">
          <span>© {new Date().getFullYear()} XPN Social</span>
          <span>Need help? Contact your administrator.</span>
        </footer>
      </section>
      <aside
        aria-label="About XPN Social"
        className="relative m-3 hidden overflow-hidden rounded-2xl bg-[#e7f0f9] lg:flex lg:flex-col lg:p-12 xl:p-16"
      >
        <div className="my-auto w-full py-10">
          <div className="mx-auto flex size-64 items-center justify-center rounded-full border border-primary/10 bg-card/50 shadow-card xl:size-80">
            <div className="flex size-44 items-center justify-center rounded-full border border-primary/15 bg-card xl:size-56">
              <ShieldCheck
                className="size-20 text-primary"
                strokeWidth={1}
                aria-hidden="true"
              />
            </div>
          </div>
        </div>
        <div>
          <h2 className="text-3xl leading-tight font-semibold tracking-tight xl:text-4xl">
            A stronger community.
            <br />
            Behind every artist.
          </h2>
          <p className="mt-4 max-w-sm text-sm leading-6 text-muted-foreground">
            One workspace to support artists, care for the community, and keep
            XPN Social running smoothly.
          </p>
        </div>
      </aside>
    </main>
  );
}
