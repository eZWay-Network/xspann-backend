import { useApp } from "@/contexts/app";
import { useCallback, useEffect, useRef, useState } from "react";
import { router } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogTitle,
  DialogDescription,
  DialogDismiss,
} from "@/components/ui/dialog";
import { Alert } from "@/components/ui/alert";
import { ReasonField } from "@/components/staff/review-forms";
function Snapshot({
  value,
  formatDateTime,
}: {
  value: unknown;
  formatDateTime: (value: string) => string;
}) {
  if (value === null || value === undefined) return <span>—</span>;
  if (typeof value !== "object")
    return (
      <span className="whitespace-pre-wrap break-words">{String(value)}</span>
    );
  return (
    <dl className="space-y-3">
      {Object.entries(value).map(([key, item]) => (
        <div key={key}>
          <dt className="text-xs text-muted-foreground">
            {key.replaceAll("_", " ")}
          </dt>
          <dd className="mt-1 text-sm">
            {key.endsWith("_at") && typeof item === "string" ? (
              formatDateTime(item)
            ) : (
              <Snapshot value={item} formatDateTime={formatDateTime} />
            )}
          </dd>
        </div>
      ))}
    </dl>
  );
}
export function Disclosure({
  url,
  contact = false,
}: {
  url: string;
  contact?: boolean;
}) {
  const { csrf_token, auth, formatDateTime } = useApp();
  const [open, setOpen] = useState(false);
  const [data, setData] = useState<Record<string, unknown> | null>(null);
  const [reason, setReason] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const active = useRef<AbortController | null>(null);
  const clear = useCallback(() => {
    active.current?.abort();
    active.current = null;
    setData(null);
    setOpen(false);
    setReason("");
    setError("");
    setBusy(false);
  }, []);
  useEffect(() => {
    clear();
    const offBefore = router.on("before", clear);
    const offNavigate = router.on("navigate", clear);
    window.addEventListener("pagehide", clear);
    const expiry = Math.min(
      Date.parse(auth?.mfa_expires_at ?? ""),
      Date.parse(auth?.session_expires_at ?? ""),
    );
    const timer = window.setTimeout(clear, Math.max(0, expiry - Date.now()));
    return () => {
      offBefore();
      offNavigate();
      window.removeEventListener("pagehide", clear);
      clearTimeout(timer);
      active.current?.abort();
      active.current = null;
    };
  }, [
    clear,
    url,
    auth?.user.id,
    auth?.role,
    auth?.mfa_expires_at,
    auth?.session_expires_at,
  ]);
  async function load() {
    active.current?.abort();
    const controller = new AbortController();
    active.current = controller;
    setBusy(true);
    setError("");
    try {
      const response = await fetch(url, {
        method: contact ? "POST" : "GET",
        credentials: "same-origin",
        cache: "no-store",
        signal: controller.signal,
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": csrf_token,
        },
        body: contact ? JSON.stringify({ reason }) : undefined,
      });
      if (
        response.status === 422 &&
        response.headers.get("Content-Type")?.includes("application/json")
      ) {
        const failure = (await response.json()) as {
          errors?: Record<string, string[]>;
        };
        throw new Error(
          Object.values(failure.errors ?? {}).flat()[0] ??
            "Check the purpose of access before trying again.",
        );
      }
      if (
        !response.ok ||
        !response.headers.get("Content-Type")?.includes("application/json")
      )
        throw new Error(
          "Access expired or this record is no longer available. Refresh the page before trying again.",
        );
      const result = (await response.json()) as Record<string, unknown>;
      if (!controller.signal.aborted && active.current === controller)
        setData(result);
    } catch (failure) {
      if (!controller.signal.aborted && active.current === controller)
        setError(
          failure instanceof Error
            ? failure.message
            : "Unable to load this record.",
        );
    } finally {
      if (active.current === controller) setBusy(false);
    }
  }
  const media = typeof data?.media_url === "string" ? data.media_url : null;
  return (
    <>
      <Button
        variant="secondary"
        onClick={() => {
          setOpen(true);
          if (!contact) void load();
        }}
      >
        {contact ? "Reveal contact" : "Open reported evidence"}
      </Button>
      <Dialog
        open={open}
        onOpenChange={(value) => {
          if (!value) clear();
        }}
      >
        <DialogContent>
          <DialogTitle>
            {contact ? "Contact details" : "Reported evidence"}
          </DialogTitle>
          <DialogDescription>
            This access is audited. Close this view when you finish reviewing.
          </DialogDescription>
          <div className="mt-5 max-h-[65vh] space-y-4 overflow-y-auto">
            {error && <Alert>{error}</Alert>}
            {busy && <p role="status">Loading…</p>}
            {contact && !data && (
              <form
                className="space-y-4"
                onSubmit={(event) => {
                  event.preventDefault();
                  void load();
                }}
              >
                <ReasonField
                  maxLength={500}
                  label="Purpose of contact access"
                  value={reason}
                  onChange={setReason}
                />
                <Button type="submit" disabled={busy}>
                  Reveal email
                </Button>
              </form>
            )}
            {data && (
              <Snapshot
                formatDateTime={formatDateTime}
                value={Object.fromEntries(
                  Object.entries(data).filter(([key]) => key !== "media_url"),
                )}
              />
            )}
            {media && (
              <a
                className="text-sm text-primary underline"
                href={media}
                target="_blank"
                rel="noopener noreferrer"
              >
                Open case-bound original media
              </a>
            )}
          </div>
          <Button variant="secondary" className="mt-5" onClick={clear}>
            Close
          </Button>
          <DialogDismiss />
        </DialogContent>
      </Dialog>
    </>
  );
}
