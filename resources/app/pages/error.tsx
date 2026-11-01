import { Head } from "@inertiajs/react";
import { ShieldAlert } from "lucide-react";
import Brand from "@/components/brand";
import { buttonVariants } from "@/components/ui/button";
const messages: Record<number, [string, string]> = {
  401: [
    "Sign in again",
    "Your staff session has expired. Sign in to continue.",
  ],
  403: [
    "Access unavailable",
    "Your account does not have access to this page or its access has changed.",
  ],
  404: ["Page not found", "This page or record is no longer available."],
  419: [
    "Your page expired",
    "Refresh your session and review your action before submitting again.",
  ],
  422: [
    "Check the requested filters",
    "Choose a valid reporting period of up to 90 days, ending today or earlier.",
  ],
  429: [
    "Please wait a moment",
    "There have been too many attempts. Try again in five minutes.",
  ],
  503: [
    "Temporarily unavailable",
    "A required service is unavailable. Please try again shortly.",
  ],
};
export default function ErrorPage({
  status,
  login_url,
  retry_url,
}: {
  status: number;
  login_url: string;
  retry_url: string;
}) {
  const [title, description] = messages[status] ?? [
    "We couldn’t complete that request",
    "Please try again. If the problem continues, contact your operator.",
  ];
  return (
    <main className="flex min-h-dvh flex-col items-center justify-center gap-8 p-6">
      <Head title={`${title} · XPN Social`} />
      <Brand />
      <section className="surface w-full max-w-md p-8 text-center">
        <ShieldAlert
          className="mx-auto mb-5 size-10 text-primary"
          strokeWidth={1.5}
        />
        <p className="mb-2 text-xs text-muted-foreground">{status}</p>
        <h1 className="text-2xl font-semibold">{title}</h1>
        <p className="mt-3 text-description">{description}</p>
        <div className="mt-6 flex flex-wrap justify-center gap-3">
          <a
            href={retry_url}
            className={buttonVariants({ variant: "secondary" })}
          >
            Open workspace
          </a>
          <a href={login_url} className={buttonVariants()}>
            Sign in again
          </a>
        </div>
      </section>
    </main>
  );
}
