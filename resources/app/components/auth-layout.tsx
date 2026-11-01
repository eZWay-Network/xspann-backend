import type { ReactNode } from "react";
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
    <main className="flex min-h-dvh flex-col items-center justify-center bg-card px-6 py-12">
      <section className="w-full max-w-sm" aria-labelledby="auth-title">
        <header className="mb-8 text-center">
          <Brand className="mx-auto mb-8 h-14" />
          <h1 id="auth-title" className="text-2xl font-semibold tracking-tight">
            {title}
          </h1>
          <p className="mt-2 text-description">{description}</p>
        </header>
        {children}
        <footer className="mt-8 border-t pt-6 text-center text-xs leading-5 text-muted-foreground">
          Need help? Contact your administrator.
        </footer>
      </section>
    </main>
  );
}
