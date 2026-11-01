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
  links?: { label: string; url: string }[];
  recovery?: (RecoveryAction & { key: string }) | null;
};
export type RecordsProps = {
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
}: RecordsProps) {
  const [search, setSearch] = useState(filters.q);
  const [references, setReferences] = useState<Record<string, string>>({});
  useEffect(() => {
    setSearch(filters.q);
    setReferences(
      Object.fromEntries(
        filter_fields.map((key) => [key, String(filters[key] ?? "")]),
      ),
    );
  }, [filters]);
  const [busy, setBusy] = useState(false);
  const { page } = usePage<AppPageProps>().props;
  const { formatDateTime } = useApp();
  function visit(values: Record<string, string | number>) {
    router.get(list_url, values, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
    });
  }
  return (
    <div className="space-y-6">
      <PageHeader
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
          className="flex flex-wrap items-center gap-3 border-b p-4"
          onSubmit={(event) => {
            event.preventDefault();
            visit({
              ...filters,
              ...references,
              q: search,
              page: 1,
              cursor: "",
            });
          }}
        >
          <RecordsSearch
            value={search}
            onChange={setSearch}
            placeholder="Search by name or reference"
          />
          {filter_fields.map((key) => (
            <Input
              key={key}
              className="w-32"
              aria-label={key.replaceAll("_", " ")}
              placeholder={key.replaceAll("_", " ")}
              inputMode="numeric"
              pattern="[1-9][0-9]{0,14}"
              value={references[key] ?? ""}
              onChange={(event) =>
                setReferences({ ...references, [key]: event.target.value })
              }
            />
          ))}
          {Object.entries(filter_options).map(([key, options]) => (
            <Select
              key={key}
              label={key.replaceAll("_", " ")}
              value={String(filters[key] ?? "")}
              options={[
                { value: "", label: `All ${key.replaceAll("_", " ")}` },
                ...options,
              ]}
              onValueChange={(value) =>
                visit({ ...filters, [key]: value, page: 1, cursor: "" })
              }
            />
          ))}
          <Select
            label="Sort order"
            value={filters.sort}
            options={[
              { value: "newest", label: "Newest first" },
              { value: "oldest", label: "Oldest first" },
            ]}
            onValueChange={(value) =>
              visit({ ...filters, sort: value, page: 1, cursor: "" })
            }
          />
          <Button type="submit" size="sm" disabled={busy}>
            Search
          </Button>
          <Button
            variant="ghost"
            size="sm"
            type="button"
            disabled={busy}
            onClick={() => {
              setSearch("");
              visit({});
            }}
          >
            Reset
          </Button>
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
              title="No records found"
              description="Try adjusting your filters or clearing the search."
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
