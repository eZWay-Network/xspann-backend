import {
  ModerationForm,
  type ModerationProps,
} from "@/components/staff/moderation-form";
import { FormErrors } from "@/components/form-errors";
import type { AppPageProps } from "@/types";
import { Link, usePage } from "@inertiajs/react";
import type { ReactNode } from "react";
import {
  Clock3,
  Check,
  X,
  ExternalLink,
  ArrowUpRight,
  ShieldCheck,
  UserX,
  Trash2,
  AlertTriangle,
  Lock,
  Archive,
  History,
  Video,
  Music2,
  ShieldAlert,
} from "lucide-react";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { buttonVariants } from "@/components/ui/button";
import { Avatar } from "@/components/ui/avatar";
import { CaseForm } from "@/components/staff/review-forms";
import { useApp } from "@/contexts/app";
import { cn } from "@/lib/utils";

export type DetailField = {
  label: string;
  value: string | number | boolean | null;
};

export type RecordLink = { label: string; url: string };
export type CaseTarget = { type: string; id: number; url: string };

export type DetailProps = {
  heading?: "h1" | "h2";
  moderation?: ModerationProps | null;
  preview_url?: string | null;
  fields: DetailField[];
  links?: RecordLink[];
  history?: RecordLink[];
  public_links?: RecordLink[];
  case_target?: CaseTarget | null;
  actions?: ReactNode;
  children?: ReactNode;
};

function getStatusConfig(status: string) {
  const normalized = (status || "").toLowerCase().trim();

  if (
    [
      "active",
      "approved",
      "clear",
      "resolved",
      "paid",
      "completed",
      "verified",
      "enabled",
      "published",
    ].includes(normalized)
  ) {
    return {
      tone: "success" as const,
      dotColor: "bg-emerald-500",
      label:
        normalized === "approved" ? "Clear" : normalized.replaceAll("_", " "),
    };
  }

  if (
    [
      "reviewing",
      "open",
      "pending",
      "action_required",
      "in_review",
      "trialing",
      "incomplete",
      "paused",
    ].includes(normalized)
  ) {
    return {
      tone: "warning" as const,
      dotColor: "bg-amber-500 animate-pulse",
      label: normalized.replaceAll("_", " "),
    };
  }

  if (
    [
      "banned",
      "suspended",
      "quarantined",
      "removed",
      "failed",
      "rejected",
      "past_due",
      "deleted",
      "soft_delete",
      "canceled",
      "disputed",
    ].includes(normalized)
  ) {
    return {
      tone: "danger" as const,
      dotColor: "bg-rose-500",
      label: normalized.replaceAll("_", " "),
    };
  }

  if (
    ["restricted", "processing", "escalated", "boost", "feature"].includes(
      normalized,
    )
  ) {
    return {
      tone: "info" as const,
      dotColor: "bg-primary",
      label: normalized.replaceAll("_", " "),
    };
  }

  return {
    tone: "neutral" as const,
    dotColor: "bg-muted-foreground",
    label: normalized.replaceAll("_", " ") || "—",
  };
}

function parseHistoryAction(label: string) {
  const parts = label.split(" · ");
  const action = (parts[0] ?? "").trim();
  const caseRef = (parts[1] ?? "").trim();
  const actionLower = action.toLowerCase();

  let tone: "danger" | "warning" | "info" | "neutral" = "neutral";
  let Icon = ShieldCheck;

  if (actionLower.includes("ban") || actionLower.includes("remove")) {
    tone = "danger";
    Icon = actionLower.includes("ban") ? UserX : Trash2;
  } else if (
    actionLower.includes("suspend") ||
    actionLower.includes("quarantine")
  ) {
    tone = "warning";
    Icon = AlertTriangle;
  } else if (actionLower.includes("restrict")) {
    tone = "info";
    Icon = Lock;
  } else if (
    actionLower.includes("soft_delete") ||
    actionLower.includes("delete")
  ) {
    tone = "neutral";
    Icon = Archive;
  }

  return { action, caseRef, tone, Icon };
}

export function DetailFields({
  fields,
  title = "Record Attributes & Metadata",
}: {
  fields: DetailField[];
  title?: string;
}) {
  const { formatDateTime } = useApp();

  return (
    <Card className="overflow-hidden border-border/80 shadow-card">
      <CardHeader className="border-b border-border/70 bg-muted/20 pb-4">
        <div>
          <CardTitle className="text-base font-semibold">{title}</CardTitle>
          <CardDescription className="text-xs text-muted-foreground">
            Current system properties and verified audit timestamps.
          </CardDescription>
        </div>
      </CardHeader>

      <dl className="grid gap-4 p-5 sm:grid-cols-2 sm:p-6">
        {fields.map((field) => {
          const isStatus = field.label.toLowerCase().includes("status");
          const isDate =
            typeof field.value === "string" &&
            /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/.test(field.value);
          const isBoolean = typeof field.value === "boolean";
          const isId =
            field.label.toLowerCase() === "id" ||
            field.label.toLowerCase().endsWith(" id");
          const isLongText =
            typeof field.value === "string" && field.value.length > 60;

          return (
            <div
              key={field.label}
              className={cn(
                "min-w-0 rounded-lg border border-border/60 bg-muted/15 p-3.5 transition-colors hover:border-border",
                isLongText && "sm:col-span-2",
              )}
            >
              <dt className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                {field.label}
              </dt>

              <dd className="mt-2 text-sm text-foreground">
                {field.value === null || field.value === "" ? (
                  <span className="text-muted-foreground/60">—</span>
                ) : isStatus ? (
                  (() => {
                    const statusConfig = getStatusConfig(String(field.value));
                    return (
                      <Badge
                        tone={statusConfig.tone}
                        className="gap-1.5 font-medium"
                      >
                        <span
                          className={cn(
                            "size-1.5 rounded-full shrink-0",
                            statusConfig.dotColor,
                          )}
                        />
                        {statusConfig.label}
                      </Badge>
                    );
                  })()
                ) : isBoolean ? (
                  field.value ? (
                    <span className="inline-flex items-center gap-1.5 font-medium text-emerald-700 dark:text-emerald-400">
                      <Check className="size-4" />
                      Yes
                    </span>
                  ) : (
                    <span className="inline-flex items-center gap-1.5 font-medium text-muted-foreground">
                      <X className="size-4 text-muted-foreground/60" />
                      No
                    </span>
                  )
                ) : isDate ? (
                  <div className="flex items-center gap-1.5 font-mono text-xs text-foreground/90">
                    <Clock3 className="size-3.5 text-muted-foreground" />
                    <span>{formatDateTime(String(field.value))}</span>
                  </div>
                ) : isId ? (
                  <span className="inline-flex items-center rounded bg-muted px-2 py-0.5 font-mono text-xs font-medium text-foreground">
                    #{String(field.value)}
                  </span>
                ) : (
                  <p className="whitespace-pre-wrap break-words leading-relaxed font-normal">
                    {String(field.value)}
                  </p>
                )}
              </dd>
            </div>
          );
        })}
      </dl>
    </Card>
  );
}

export function RelatedLinks({ links }: { links: RecordLink[] }) {
  if (!links.length) return null;

  return (
    <div className="flex flex-wrap gap-2">
      {links.map((link) => (
        <Link
          key={link.url + link.label}
          href={link.url}
          className={cn(
            buttonVariants({ variant: "secondary", size: "sm" }),
            "gap-1.5 shadow-xs hover:border-primary/40 hover:text-primary transition-colors",
          )}
        >
          <span>{link.label}</span>
          <ArrowUpRight className="size-3.5 opacity-70" />
        </Link>
      ))}
    </div>
  );
}

export function DetailScreen({
  fields,
  moderation,
  preview_url,
  links = [],
  history = [],
  heading: _heading,
  public_links = [],
  case_target,
  actions,
  children,
}: DetailProps) {
  const { page, errors } = usePage<AppPageProps>().props;

  // Extract key fields for Hero Banner
  const usernameField = fields.find(
    (f) => f.label.toLowerCase() === "username",
  );
  const nameField = fields.find(
    (f) =>
      f.label.toLowerCase() === "name" ||
      f.label.toLowerCase() === "stage name" ||
      f.label.toLowerCase() === "title",
  );
  const statusField = fields.find(
    (f) =>
      f.label.toLowerCase().includes("status") ||
      f.label.toLowerCase().includes("publication state"),
  );
  const idField = fields.find((f) => f.label.toLowerCase() === "id");
  const captionField = fields.find(
    (f) =>
      f.label.toLowerCase() === "caption" || f.label.toLowerCase() === "body",
  );

  const primaryName = String(nameField?.value ?? page.title);
  const statusConfig = statusField?.value
    ? getStatusConfig(String(statusField.value))
    : null;

  // Media Type Inspection
  const isVideo = Boolean(
    preview_url &&
    (page.title.toLowerCase().includes("video") ||
      fields.some(
        (f) =>
          f.label.toLowerCase() === "caption" ||
          f.label.toLowerCase() === "video id" ||
          f.label.toLowerCase() === "dependent audio",
      )),
  );

  const isAudio = Boolean(
    preview_url &&
    !isVideo &&
    (page.title.toLowerCase().includes("audio") ||
      fields.some(
        (f) =>
          f.label.toLowerCase() === "duration" ||
          f.label.toLowerCase() === "source video id" ||
          f.label.toLowerCase() === "affected videos",
      )),
  );

  return (
    <div className="space-y-8">
      {/* Hero Entity Header Banner */}
      <div className="rounded-xl border border-border/80 bg-card p-6 shadow-card">
        <div className="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex items-start gap-4">
            {isVideo ? (
              <div className="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-rose-500/10 text-rose-600 border border-rose-500/20 shadow-xs">
                <Video className="size-8" strokeWidth={1.8} />
              </div>
            ) : isAudio ? (
              <div className="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-purple-500/10 text-purple-600 border border-purple-500/20 shadow-xs">
                <Music2 className="size-8" strokeWidth={1.8} />
              </div>
            ) : (
              <Avatar
                name={primaryName}
                className="size-16 rounded-2xl border-2 border-border shadow-xs shrink-0"
              />
            )}

            <div>
              <div className="flex flex-wrap items-center gap-2.5">
                <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
                  {primaryName}
                </h1>

                {statusConfig && (
                  <Badge
                    tone={statusConfig.tone}
                    className="gap-1.5 font-medium"
                  >
                    <span
                      className={cn(
                        "size-1.5 rounded-full shrink-0",
                        statusConfig.dotColor,
                      )}
                    />
                    {statusConfig.label}
                  </Badge>
                )}

                {idField?.value && (
                  <span className="font-mono text-xs text-muted-foreground bg-muted px-2 py-0.5 rounded">
                    #{String(idField.value)}
                  </span>
                )}
              </div>

              {captionField?.value && (
                <p className="mt-1.5 line-clamp-2 max-w-2xl text-xs text-foreground/80 leading-relaxed font-normal">
                  "{String(captionField.value)}"
                </p>
              )}

              {usernameField?.value && (
                <p className="mt-1 font-mono text-xs text-muted-foreground">
                  @{String(usernameField.value)}
                </p>
              )}

              <p className="mt-1 text-xs text-muted-foreground">
                Operational staff inspection and enforcement record.
              </p>
            </div>
          </div>

          <div className="flex flex-wrap items-center gap-2.5 pt-1">
            {preview_url && (
              <a
                href={preview_url}
                target="_blank"
                rel="noopener noreferrer"
                className={cn(
                  buttonVariants({ variant: "secondary", size: "sm" }),
                  "gap-1.5 text-xs shadow-xs",
                )}
              >
                <span>External Preview</span>
                <ExternalLink className="size-3.5 opacity-70" />
              </a>
            )}

            {case_target && !moderation?.choices.length && (
              <a
                href="#case-form-section"
                className={cn(
                  buttonVariants({ variant: "secondary", size: "sm" }),
                  "gap-1.5 text-xs text-destructive hover:bg-destructive-soft shadow-xs",
                )}
              >
                <ShieldAlert className="size-3.5" />
                <span>Open Case</span>
              </a>
            )}

            {actions}
          </div>
        </div>
      </div>

      <FormErrors errors={{ form: errors.form }} />

      {/* Modern Two-Column Layout for Short Video Content (5/12 Video Player + 7/12 Details) */}
      {isVideo ? (
        <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-12">
          {/* Left Column: Short Video Portrait Smartphone Bezel (5 of 12) */}
          <div className="flex flex-col gap-4 lg:col-span-5">
            <Card className="overflow-hidden border-border/80 bg-neutral-950 text-white shadow-lg">
              {/* Top Status Bar */}
              <div className="flex items-center justify-between border-b border-white/10 bg-black/60 px-4 py-3 backdrop-blur-md">
                <div className="flex items-center gap-2">
                  <span className="size-2 rounded-full bg-rose-500 animate-pulse" />
                  <span className="text-[11px] font-semibold tracking-wider uppercase text-white/90">
                    Short Video Stream
                  </span>
                </div>
                <span className="rounded bg-white/10 px-2 py-0.5 text-[10px] font-mono text-white/70">
                  9:16 Portrait
                </span>
              </div>

              {/* 9:16 Portrait Smartphone Frame */}
              <div className="relative mx-auto flex w-full justify-center p-3 sm:p-5">
                <div className="relative aspect-[9/16] w-full max-w-[340px] overflow-hidden rounded-2xl border-2 border-white/15 bg-black shadow-2xl">
                  <video
                    className="size-full object-cover"
                    controls
                    playsInline
                    preload="metadata"
                    src={preview_url!}
                  />
                </div>
              </div>

              {/* Bottom Quick Tools Bar */}
              <div className="flex items-center justify-between border-t border-white/10 bg-black/40 px-5 py-3 text-xs text-white/70">
                <span className="text-[11px]">Stream verified</span>
                <a
                  href={preview_url!}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="inline-flex items-center gap-1 font-medium text-white hover:underline"
                >
                  Raw source
                  <ExternalLink className="size-3" />
                </a>
              </div>
            </Card>
          </div>

          {/* Right Column: Video Attributes, Author & Connected Media (7 of 12) */}
          <div className="space-y-6 lg:col-span-7">
            <DetailFields fields={fields} title="Video Attributes & Metrics" />

            {links.length > 0 && (
              <Card className="border-border/80 p-5 shadow-card">
                <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Creator & Connected Media
                </h2>
                <RelatedLinks links={links} />
              </Card>
            )}

            {public_links.length > 0 && (
              <Card className="border-border/80 p-5 shadow-card">
                <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  External References
                </h2>
                <div className="flex flex-wrap gap-2.5">
                  {public_links.map((link) => (
                    <a
                      className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-xs hover:bg-muted/70 transition-colors"
                      key={link.url}
                      href={link.url}
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      <span>{link.label}</span>
                      <ExternalLink className="size-3 text-muted-foreground" />
                    </a>
                  ))}
                </div>
              </Card>
            )}
          </div>
        </div>
      ) : isAudio ? (
        /* Modern Layout for Audio Assets */
        <div className="space-y-6">
          <Card className="overflow-hidden border-border/80 shadow-card">
            <CardHeader className="border-b border-border/70 bg-purple-500/5 pb-4">
              <div className="flex items-center justify-between">
                <div>
                  <CardTitle className="text-base font-semibold text-foreground">
                    Audio Stream Playback
                  </CardTitle>
                  <CardDescription className="text-xs text-muted-foreground">
                    Listen to verified audio asset stream and inspect dependent
                    videos.
                  </CardDescription>
                </div>
                <div className="flex size-9 items-center justify-center rounded-lg bg-purple-500/10 text-purple-600">
                  <Music2 className="size-5" />
                </div>
              </div>
            </CardHeader>
            <div className="p-5 sm:p-6">
              <audio controls className="w-full shadow-xs" src={preview_url!} />
            </div>
          </Card>

          <DetailFields fields={fields} title="Audio Attributes & Usage" />

          {links.length > 0 && (
            <Card className="border-border/80 p-5 shadow-card">
              <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Connected Creator & Affected Videos
              </h2>
              <RelatedLinks links={links} />
            </Card>
          )}
        </div>
      ) : (
        /* Standard Layout for Users, Artists, Moderation Records, Billing */
        <div className="space-y-6">
          <DetailFields fields={fields} />

          {links.length > 0 && (
            <Card className="border-border/80 p-5 shadow-card">
              <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                Connected Resources
              </h2>
              <RelatedLinks links={links} />
            </Card>
          )}

          {public_links.length > 0 && (
            <Card className="border-border/80 p-5 shadow-card">
              <h2 className="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                External References
              </h2>
              <div className="flex flex-wrap gap-2.5">
                {public_links.map((link) => (
                  <a
                    className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-1.5 text-xs font-medium text-foreground shadow-xs hover:bg-muted/70 transition-colors"
                    key={link.url}
                    href={link.url}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    <span>{link.label}</span>
                    <ExternalLink className="size-3 text-muted-foreground" />
                  </a>
                ))}
              </div>
            </Card>
          )}

          {/* Fallback preview for any other media */}
          {preview_url && !isVideo && !isAudio && (
            <Card className="overflow-hidden border-border/80 shadow-card">
              <CardHeader className="border-b border-border/70 bg-muted/20 pb-4">
                <CardTitle className="text-base font-semibold">
                  Media Preview
                </CardTitle>
                <CardDescription className="text-xs text-muted-foreground">
                  Direct evidence preview for this asset.
                </CardDescription>
              </CardHeader>
              <div className="p-5">
                <video
                  className="max-h-96 w-full rounded-lg bg-black"
                  controls
                  preload="metadata"
                  src={preview_url}
                />
              </div>
            </Card>
          )}
        </div>
      )}

      {/* Children Section (e.g. Playlist items, Comment replies, Disclosures) */}
      {children}

      {/* Moderation Form Workbench */}
      {moderation && (
        <ModerationForm
          key={`${moderation.type}:${moderation.target_version}`}
          moderation={moderation}
        />
      )}

      {/* Review Case Form Trigger */}
      {case_target && !moderation?.choices.length && (
        <div id="case-form-section">
          <CaseForm target={case_target} />
        </div>
      )}

      {/* Recent Moderation Decisions Timeline */}
      {history.length > 0 && (
        <Card className="overflow-hidden border-border/80 shadow-card">
          <CardHeader className="border-b border-border/70 bg-muted/20 pb-4">
            <div className="flex items-center gap-2">
              <History className="size-4 text-muted-foreground" />
              <CardTitle className="text-base font-semibold">
                Enforcement History & Decisions
              </CardTitle>
            </div>
            <CardDescription className="text-xs text-muted-foreground">
              Audit trail of moderation actions previously taken on this
              subject.
            </CardDescription>
          </CardHeader>

          <div className="divide-y divide-border/60">
            {history.map((item, idx) => {
              const { action, caseRef, tone, Icon } = parseHistoryAction(
                item.label,
              );

              return (
                <div
                  key={idx}
                  className="flex items-center justify-between p-4 transition-colors hover:bg-muted/30"
                >
                  <div className="flex items-center gap-3">
                    <div
                      className={cn(
                        "flex size-8 items-center justify-center rounded-lg border",
                        tone === "danger" &&
                          "border-destructive/20 bg-destructive-soft text-destructive",
                        tone === "warning" &&
                          "border-warning/20 bg-warning-soft text-warning",
                        tone === "info" &&
                          "border-primary/20 bg-accent text-primary",
                        tone === "neutral" &&
                          "border-border bg-muted text-muted-foreground",
                      )}
                    >
                      <Icon className="size-3.5" />
                    </div>

                    <div>
                      <div className="flex items-center gap-2">
                        <Badge tone={tone} className="capitalize font-medium">
                          {action}
                        </Badge>
                        {caseRef && (
                          <span className="font-mono text-xs text-muted-foreground">
                            {caseRef}
                          </span>
                        )}
                      </div>
                    </div>
                  </div>

                  <Link
                    href={item.url}
                    className={cn(
                      buttonVariants({ variant: "secondary", size: "sm" }),
                      "gap-1 text-xs",
                    )}
                  >
                    <span>View Case</span>
                    <ArrowUpRight className="size-3" />
                  </Link>
                </div>
              );
            })}
          </div>
        </Card>
      )}
    </div>
  );
}
