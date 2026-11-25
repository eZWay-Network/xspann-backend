import {
  RecoveryDialog,
  type RecoveryAction,
} from "@/components/staff/recovery-form";
import type { AppPageProps } from "@/types";
import { useEffect, useState, type ReactNode } from "react";
import { Link, router, usePage } from "@inertiajs/react";
import {
  Search,
  Filter,
  RotateCcw,
  ArrowUpRight,
  Check,
  Music2,
  ShieldCheck,
  ShieldAlert,
  Scale,
  Video,
  MessageSquare,
  ListMusic,
  Receipt,
  Activity,
  Clock3,
} from "lucide-react";
import { RecordsSearch } from "@/components/records-search";
import {
  RecordsPagination,
  CursorPagination,
} from "@/components/records-pagination";
import { EmptyState } from "@/components/empty-state";
import { Button, buttonVariants } from "@/components/ui/button";
import { Select } from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Card } from "@/components/ui/card";
import { Table, TableCell, TableHead } from "@/components/ui/table";
import { Avatar } from "@/components/ui/avatar";
import { useApp } from "@/contexts/app";
import { cn } from "@/lib/utils";

export type RecordRow = {
  id: number;
  audit?: Record<string, unknown>;
  title: string;
  url: string | null;
  status: string;
  description: string | null;
  created_at: string | null;
  starts_at?: string | null;
  ends_at?: string | null;
  links?: { label: string; url: string }[];
  recovery?: (RecoveryAction & { key: string }) | null;
};

export type RecordsProps = {
  emptyState?: { noun: string; description: string };
  heading?: "h1" | "h2";
  rows: RecordRow[];
  pagination:
    | { kind: "page"; current_page: number; last_page: number; total: number }
    | { kind: "cursor"; next_url: string | null; previous_url: string | null };
  filters: {
    q: string;
    sort: string;
    per_page: number;
    page: number;
    [key: string]: string | number;
  };
  filter_options: Record<string, { value: string; label: string }[]>;
  filter_fields?: string[];
  list_url: string;
  description: string;
  create_url?: string | null;
  children?: ReactNode;
  renderAction?: (row: RecordRow) => ReactNode;
};

function fieldLabel(key: string) {
  const words = key.replaceAll("_", " ").replace(/\bid\b/g, "ID");
  return words.charAt(0).toUpperCase() + words.slice(1);
}

function getStatusConfig(status: string, listUrl: string) {
  const normalized = (status || "").toLowerCase().trim();

  if (normalized === "approved" && listUrl.includes("/content/")) {
    return {
      tone: "success" as const,
      dotColor: "bg-emerald-500",
      label: "Clear",
    };
  }

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
      label: normalized.replaceAll("_", " "),
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

export function RecordsScreen({
  rows,
  pagination,
  filters,
  filter_options,
  filter_fields = [],
  list_url,
  description,
  create_url,
  children,
  renderAction,
  heading: _heading,
  emptyState,
}: RecordsProps) {
  const [draft, setDraft] = useState(filters);
  useEffect(() => setDraft(filters), [filters]);

  const update = (key: string, value: string) =>
    setDraft((current) => ({ ...current, [key]: value }));

  const activeFiltersCount = [
    Boolean(filters.q),
    ...[...filter_fields, ...Object.keys(filter_options)].map((k) =>
      Boolean(filters[k]),
    ),
  ].filter(Boolean).length;

  const filtered = activeFiltersCount > 0;
  const noun = emptyState?.noun ?? "records";
  const [busy, setBusy] = useState(false);
  const { page } = usePage<AppPageProps>().props;
  const { formatDateTime } = useApp();

  // Module category detections
  const isUsersModule =
    list_url.includes("/users") || page.title.toLowerCase().includes("user");
  const isArtistsModule =
    list_url.includes("/artists") ||
    page.title.toLowerCase().includes("artist");
  const isCommentsModule = list_url.includes("/comments");
  const isAudiosModule = list_url.includes("/audios");
  const isVideosModule = list_url.includes("/videos");
  const isPlaylistsModule = list_url.includes("/playlists");
  const isReportsModule = list_url.includes("/reports");
  const isAppealsModule = list_url.includes("/appeals");
  const isBillingModule =
    list_url.includes("/billing") ||
    list_url.includes("/invoices") ||
    list_url.includes("/subscriptions");

  function visit(values: Record<string, string | number>) {
    router.get(list_url, values, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      onSuccess: (response) =>
        setDraft(response.props.filters as RecordsProps["filters"]),
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
    });
  }

  return (
    <div className="space-y-6">
      {/* Module Page Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2.5">
            <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
              {page.title}
            </h1>
            <span className="inline-flex items-center gap-1 rounded-full border border-border bg-muted/60 px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
              {pagination.kind === "page"
                ? `${pagination.total.toLocaleString()} total`
                : `${rows.length} records`}
            </span>
          </div>
          {description && (
            <p className="mt-1 text-sm text-muted-foreground">{description}</p>
          )}
        </div>

        {create_url && (
          <Link
            className={buttonVariants({ variant: "primary", size: "default" })}
            href={create_url}
          >
            Create placement
          </Link>
        )}
      </div>

      {children}

      {/* Main Records Container */}
      <Card className="overflow-hidden border-border/80 shadow-card">
        {/* Filter Toolbar */}
        <form
          onSubmit={(event) => {
            event.preventDefault();
            const { page: _p, cursor: _c, ...criteria } = draft;
            visit(
              Object.fromEntries(
                Object.entries(criteria).filter(([, value]) => value !== ""),
              ),
            );
          }}
          className="border-b border-border/80 bg-muted/20"
        >
          <fieldset
            disabled={busy}
            className="flex flex-wrap items-center gap-3 p-4 sm:px-6"
          >
            <legend className="sr-only">Record search and filters</legend>

            <RecordsSearch
              value={draft.q}
              onChange={(value) => update("q", value)}
              placeholder="Search by name, handle, or reference…"
              className="w-full sm:max-w-xs"
            />

            {filter_fields.map((key) => (
              <Input
                key={key}
                className="w-32 bg-card"
                aria-label={fieldLabel(key)}
                placeholder={fieldLabel(key)}
                type={["from", "to"].includes(key) ? "date" : "text"}
                inputMode={key.endsWith("_id") ? "numeric" : undefined}
                pattern={key.endsWith("_id") ? "[1-9][0-9]{0,14}" : undefined}
                value={String(draft[key] ?? "")}
                onChange={(event) => update(key, event.target.value)}
              />
            ))}

            {Object.entries(filter_options).map(([key, options]) => (
              <Select
                key={key}
                label={fieldLabel(key)}
                value={String(draft[key] ?? "")}
                options={[
                  {
                    value: "",
                    label:
                      key === "status"
                        ? "All statuses"
                        : `Any ${fieldLabel(key).toLowerCase()}`,
                  },
                  ...options,
                ]}
                onValueChange={(value) => update(key, value)}
              />
            ))}

            <Select
              label="Sort order"
              value={draft.sort}
              options={[
                { value: "newest", label: "Newest first" },
                { value: "oldest", label: "Oldest first" },
              ]}
              onValueChange={(value) => update("sort", value)}
            />

            <div className="flex items-center gap-2">
              <Button
                type="submit"
                loading={busy}
                className="gap-1.5 shadow-xs"
              >
                <Filter className="size-3.5" />
                Filter
              </Button>

              {filtered && (
                <Button
                  variant="ghost"
                  type="button"
                  disabled={busy}
                  onClick={() => visit({})}
                  className="gap-1.5 text-muted-foreground hover:text-foreground"
                >
                  <RotateCcw className="size-3.5" />
                  Reset
                </Button>
              )}
            </div>
          </fieldset>

          {/* Active Filters Summary Strip */}
          {filtered && (
            <div className="flex items-center justify-between border-t border-border/50 bg-accent/40 px-4 py-2 text-xs text-accent-foreground sm:px-6">
              <span className="font-medium">
                Active filters applied: {activeFiltersCount} criteria
              </span>
              <button
                type="button"
                onClick={() => visit({})}
                className="font-semibold underline hover:no-underline"
              >
                Clear all filters
              </button>
            </div>
          )}
        </form>

        {/* Data Table */}
        <div
          aria-busy={busy}
          className={busy ? "opacity-60 transition-opacity" : undefined}
        >
          {rows.length > 0 ? (
            <Table>
              <thead>
                <tr>
                  <TableHead>
                    {isUsersModule
                      ? "User Profile"
                      : isArtistsModule
                        ? "Artist"
                        : "Record"}
                  </TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Created</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </tr>
              </thead>
              <tbody className="divide-y divide-border/60">
                {rows.map((row) => {
                  const statusConfig = getStatusConfig(row.status, list_url);

                  // User parser: e.g. "John Doe · @johndoe"
                  const userMatch = row.title.match(/^(.*?) · @(.*)$/);
                  const isUserRow = Boolean(userMatch) || isUsersModule;
                  const userName = userMatch ? userMatch[1] : row.title;
                  const userHandle = userMatch ? userMatch[2] : null;

                  // Parse description tags
                  const descTags = row.description
                    ? row.description.split(" · ")
                    : [];

                  return (
                    <tr
                      key={row.id}
                      className="group/row hover:bg-muted/30 transition-colors"
                    >
                      {/* Primary Record Identity Column */}
                      <TableCell>
                        <div className="flex items-start gap-3.5 py-1">
                          {/* Entity Visual Avatar or Icon */}
                          {isUserRow ? (
                            <Avatar
                              name={userName || row.title || "User"}
                              className="size-10 rounded-full border border-border/80 shadow-xs shrink-0"
                            />
                          ) : isArtistsModule ? (
                            <Avatar
                              name={row.title}
                              className="size-10 rounded-lg border border-border/80 shadow-xs shrink-0"
                            />
                          ) : isCommentsModule ? (
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-indigo-500/10 text-indigo-600">
                              <MessageSquare className="size-4" />
                            </div>
                          ) : isAudiosModule ? (
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-purple-500/10 text-purple-600">
                              <Music2 className="size-4" />
                            </div>
                          ) : isVideosModule ? (
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-rose-500/10 text-rose-600">
                              <Video className="size-4" />
                            </div>
                          ) : isPlaylistsModule ? (
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-cyan-500/10 text-cyan-600">
                              <ListMusic className="size-4" />
                            </div>
                          ) : isReportsModule ? (
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600">
                              <ShieldAlert className="size-4" />
                            </div>
                          ) : isAppealsModule ? (
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-purple-500/10 text-purple-600">
                              <Scale className="size-4" />
                            </div>
                          ) : isBillingModule ? (
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600">
                              <Receipt className="size-4" />
                            </div>
                          ) : (
                            <div className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                              <Activity className="size-4" />
                            </div>
                          )}

                          {/* Record Info & Meta */}
                          <div className="min-w-48 max-w-xl">
                            <div className="flex flex-wrap items-center gap-2">
                              {row.url ? (
                                <Link
                                  href={row.url}
                                  className="font-semibold text-foreground hover:text-primary transition-colors hover:underline underline-offset-2"
                                >
                                  {userName}
                                </Link>
                              ) : (
                                <span className="font-semibold text-foreground">
                                  {userName}
                                </span>
                              )}

                              {userHandle && (
                                <span className="inline-flex items-center rounded-md bg-muted px-2 py-0.5 font-mono text-[11px] font-medium text-muted-foreground">
                                  @{userHandle}
                                </span>
                              )}

                              <span className="font-mono text-[11px] text-muted-foreground/70">
                                #{row.id}
                              </span>
                            </div>

                            {/* Tags or Description */}
                            {descTags.length > 0 && (
                              <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                                {descTags.map((tag, idx) => {
                                  const trimmed = tag.trim();
                                  if (trimmed === "Verified") {
                                    return (
                                      <span
                                        key={idx}
                                        className="inline-flex items-center gap-1 rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 dark:text-emerald-400 border border-emerald-500/20"
                                      >
                                        <Check className="size-3" />
                                        Verified
                                      </span>
                                    );
                                  }
                                  if (trimmed === "Unverified") {
                                    return (
                                      <span
                                        key={idx}
                                        className="inline-flex items-center rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                                      >
                                        Unverified
                                      </span>
                                    );
                                  }
                                  if (trimmed === "Artist") {
                                    return (
                                      <span
                                        key={idx}
                                        className="inline-flex items-center gap-1 rounded bg-purple-500/10 px-1.5 py-0.5 text-[10px] font-medium text-purple-700 dark:text-purple-400 border border-purple-500/20"
                                      >
                                        <Music2 className="size-3" />
                                        Artist
                                      </span>
                                    );
                                  }
                                  if (trimmed === "Staff") {
                                    return (
                                      <span
                                        key={idx}
                                        className="inline-flex items-center gap-1 rounded bg-blue-500/10 px-1.5 py-0.5 text-[10px] font-medium text-blue-700 dark:text-blue-400 border border-blue-500/20"
                                      >
                                        <ShieldCheck className="size-3" />
                                        Staff
                                      </span>
                                    );
                                  }
                                  if (trimmed === "Onboarding pending") {
                                    return (
                                      <span
                                        key={idx}
                                        className="inline-flex items-center rounded bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:text-amber-400 border border-amber-500/20"
                                      >
                                        Onboarding pending
                                      </span>
                                    );
                                  }
                                  return (
                                    <span
                                      key={idx}
                                      className="inline-flex items-center rounded bg-muted/80 px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                                    >
                                      {trimmed}
                                    </span>
                                  );
                                })}
                              </div>
                            )}

                            {/* Audit / Decision details accordion */}
                            {row.audit && Object.keys(row.audit).length > 0 && (
                              <details className="mt-2 text-xs">
                                <summary className="cursor-pointer font-medium text-muted-foreground hover:text-foreground">
                                  Decision metadata
                                </summary>
                                <dl className="mt-2 space-y-1.5 rounded-lg border border-border/60 bg-muted/30 p-2.5">
                                  {Object.entries(row.audit).map(
                                    ([key, value]) => (
                                      <div key={key} className="flex gap-2">
                                        <dt className="font-medium text-muted-foreground">
                                          {fieldLabel(key)}:
                                        </dt>
                                        <dd className="break-words">
                                          {value && typeof value === "object"
                                            ? Object.entries(value)
                                                .map(
                                                  ([f, item]) =>
                                                    `${fieldLabel(f)}: ${item ?? "—"}`,
                                                )
                                                .join("; ")
                                            : String(value ?? "—")}
                                        </dd>
                                      </div>
                                    ),
                                  )}
                                </dl>
                              </details>
                            )}

                            {(row.starts_at || row.ends_at) && (
                              <p className="mt-1 text-xs text-muted-foreground">
                                {row.starts_at && formatDateTime(row.starts_at)}
                                {row.starts_at && row.ends_at && " – "}
                                {row.ends_at && formatDateTime(row.ends_at)}
                              </p>
                            )}

                            {/* Direct secondary links */}
                            {row.links && row.links.length > 0 && (
                              <div className="mt-1.5 flex flex-wrap gap-1.5">
                                {row.links.map((link) => (
                                  <Link
                                    key={link.url}
                                    className="inline-flex items-center gap-1 rounded bg-muted px-2 py-0.5 text-[11px] font-medium text-primary hover:bg-muted/80"
                                    href={link.url}
                                  >
                                    {link.label}
                                    <ArrowUpRight className="size-3" />
                                  </Link>
                                ))}
                              </div>
                            )}
                          </div>
                        </div>
                      </TableCell>

                      {/* Status Column */}
                      <TableCell>
                        <Badge
                          tone={statusConfig.tone}
                          className="capitalize gap-1.5 font-medium"
                        >
                          <span
                            className={cn(
                              "size-1.5 rounded-full shrink-0",
                              statusConfig.dotColor,
                            )}
                          />
                          {statusConfig.label}
                        </Badge>
                      </TableCell>

                      {/* Created Column */}
                      <TableCell className="whitespace-nowrap text-muted-foreground">
                        {row.created_at ? (
                          <div className="flex items-center gap-1.5 text-xs">
                            <Clock3 className="size-3.5 text-muted-foreground/70" />
                            <span>{formatDateTime(row.created_at)}</span>
                          </div>
                        ) : (
                          "—"
                        )}
                      </TableCell>

                      {/* Actions Column */}
                      <TableCell className="text-right">
                        <div className="flex items-center justify-end gap-2">
                          {renderAction?.(row)}
                          {row.recovery && (
                            <RecoveryDialog action={row.recovery} />
                          )}
                          {row.url && (
                            <Link
                              href={row.url}
                              className={cn(
                                buttonVariants({
                                  variant: "secondary",
                                  size: "sm",
                                }),
                                "gap-1 font-medium hover:border-primary/40 hover:text-primary transition-colors",
                              )}
                              aria-label={`Open ${row.title}`}
                            >
                              <span>View</span>
                              <ArrowUpRight className="size-3.5 opacity-70" />
                            </Link>
                          )}
                        </div>
                      </TableCell>
                    </tr>
                  );
                })}
              </tbody>
            </Table>
          ) : (
            <EmptyState
              icon={Search}
              title={filtered ? `No matching ${noun}` : `No ${noun} yet`}
              description={
                filtered
                  ? "Try adjusting your search keywords or clearing your active filters."
                  : (emptyState?.description ??
                    `Records will appear here when ${noun} are registered.`)
              }
              action={
                filtered && (
                  <Button
                    variant="secondary"
                    disabled={busy}
                    onClick={() => visit({})}
                    className="gap-1.5"
                  >
                    <RotateCcw className="size-3.5" />
                    Clear all filters
                  </Button>
                )
              }
            />
          )}
        </div>
      </Card>

      {/* Pagination Footer */}
      {pagination.kind === "page" ? (
        <RecordsPagination
          busy={busy}
          page={pagination.current_page}
          lastPage={pagination.last_page}
          total={pagination.total}
          onChange={(value) => visit({ ...filters, page: value })}
        />
      ) : (
        <CursorPagination
          busy={busy}
          previous={pagination.previous_url}
          next={pagination.next_url}
          onNavigate={(url) =>
            router.get(
              url,
              {},
              {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
              },
            )
          }
        />
      )}
    </div>
  );
}
