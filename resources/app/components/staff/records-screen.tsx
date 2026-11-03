import {
  RecoveryDialog,
  type RecoveryAction,
} from "@/components/staff/recovery-form";
import type { AppPageProps } from "@/types";
import { useEffect, useState, type ReactNode } from "react";
import { Link, router, usePage } from "@inertiajs/react";
import { Search } from "lucide-react";
import { PageHeader } from "@/components/page-header";
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
import { useApp } from "@/contexts/app";

export type RecordRow = {
  id: number;
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
  heading,
  emptyState,
}: RecordsProps) {
  const [draft, setDraft] = useState(filters);
  useEffect(() => setDraft(filters), [filters]);
  const update = (key: string, value: string) =>
    setDraft((current) => ({ ...current, [key]: value }));
  const filtered = Boolean(
    filters.q ||
    [...filter_fields, ...Object.keys(filter_options)].some(
      (key) => filters[key],
    ),
  );
  const noun = emptyState?.noun ?? "records";
  const [busy, setBusy] = useState(false);
  const { page } = usePage<AppPageProps>().props;
  const { formatDateTime } = useApp();
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
      <PageHeader
        heading={heading}
        title={page.title}
        description={description}
        actions={
          create_url ? (
            <Link className={buttonVariants()} href={create_url}>
              Create placement
            </Link>
          ) : undefined
        }
      />
      {children}
      <Card className="overflow-hidden">
        <form
          onSubmit={(event) => {
            event.preventDefault();
            const { page, cursor, ...criteria } = draft;
            visit(
              Object.fromEntries(
                Object.entries(criteria).filter(([, value]) => value !== ""),
              ),
            );
          }}
        >
          <fieldset
            disabled={busy}
            className="records-toolbar min-w-0 border-b"
          >
            <legend className="sr-only">Record filters</legend>
            <RecordsSearch
              value={draft.q}
              onChange={(value) => update("q", value)}
              placeholder="Search by name or reference"
            />
            {filter_fields.map((key) => (
              <Input
                key={key}
                className="w-32"
                aria-label={fieldLabel(key)}
                placeholder={fieldLabel(key)}
                inputMode="numeric"
                pattern="[1-9][0-9]{0,14}"
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
            <Button type="submit" loading={busy}>
              Apply filters
            </Button>
            <Button
              variant="ghost"
              type="button"
              disabled={busy}
              onClick={() => visit({})}
            >
              Reset
            </Button>
          </fieldset>
        </form>
        <div aria-busy={busy} className={busy ? "opacity-60" : undefined}>
          {rows.length ? (
            <Table>
              <thead>
                <tr>
                  <TableHead>Record</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Created</TableHead>
                  <TableHead>
                    <span className="sr-only">Open record</span>
                  </TableHead>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id}>
                    <TableCell>
                      <div className="min-w-48 max-w-xl">
                        <p className="font-medium break-words">{row.title}</p>
                        {row.description && (
                          <p className="mt-1 text-xs whitespace-pre-wrap break-words text-muted-foreground">
                            {row.description}
                          </p>
                        )}
                        {(row.starts_at || row.ends_at) && (
                          <p className="mt-1 text-xs text-muted-foreground">
                            {row.starts_at && formatDateTime(row.starts_at)}
                            {row.starts_at && row.ends_at && " – "}
                            {row.ends_at && formatDateTime(row.ends_at)}
                          </p>
                        )}
                        {row.links?.map((link) => (
                          <Link
                            key={link.url}
                            className="mt-1 block text-xs text-primary underline-offset-4 hover:underline"
                            href={link.url}
                          >
                            {link.label}
                          </Link>
                        ))}
                      </div>
                    </TableCell>
                    <TableCell>
                      <Badge>{row.status.replaceAll("_", " ")}</Badge>
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-muted-foreground">
                      {row.created_at ? formatDateTime(row.created_at) : "—"}
                    </TableCell>
                    <TableCell>
                      {renderAction?.(row)}
                      {row.recovery && <RecoveryDialog action={row.recovery} />}
                      {row.url && (
                        <Link
                          href={row.url}
                          className={buttonVariants({
                            variant: "ghost",
                            size: "sm",
                          })}
                          aria-label={`Open ${row.title}`}
                        >
                          View
                        </Link>
                      )}
                    </TableCell>
                  </tr>
                ))}
              </tbody>
            </Table>
          ) : (
            <EmptyState
              icon={Search}
              title={filtered ? `No matching ${noun}` : `No ${noun} yet`}
              description={
                filtered
                  ? "Try another search or clear your filters."
                  : (emptyState?.description ??
                    "Records will appear here when available.")
              }
              action={
                filtered && (
                  <Button
                    variant="secondary"
                    disabled={busy}
                    onClick={() => visit({})}
                  >
                    Clear filters
                  </Button>
                )
              }
            />
          )}
        </div>
      </Card>
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
