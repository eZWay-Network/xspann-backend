import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";

export function RecordsPagination({
  page,
  total,
  lastPage,
  onChange,
  busy,
}: {
  page: number;
  total: number;
  lastPage: number;
  onChange: (page: number) => void;
  busy?: boolean;
}) {
  if (!total) return null;

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 px-1 text-xs text-muted-foreground">
      <div className="flex items-center gap-2">
        <span className="font-semibold text-foreground tabular-nums">
          {total.toLocaleString()}
        </span>
        <span>{total === 1 ? "record found" : "records found"}</span>
        <span className="text-border">·</span>
        <span>
          Page <strong className="font-semibold text-foreground">{page}</strong>{" "}
          of{" "}
          <strong className="font-semibold text-foreground">
            {Math.max(1, lastPage)}
          </strong>
        </span>
      </div>

      <div className="flex items-center gap-2">
        <Button
          size="sm"
          variant="secondary"
          disabled={page <= 1 || busy}
          onClick={() => onChange(page - 1)}
          className="gap-1 shadow-xs"
        >
          <ChevronLeft className="size-3.5" />
          <span>Previous</span>
        </Button>
        <Button
          size="sm"
          variant="secondary"
          disabled={page >= lastPage || busy}
          onClick={() => onChange(page + 1)}
          className="gap-1 shadow-xs"
        >
          <span>Next</span>
          <ChevronRight className="size-3.5" />
        </Button>
      </div>
    </div>
  );
}

/** Opaque cursor navigation intentionally has no invented page count or total. */
export function CursorPagination({
  previous,
  next,
  onNavigate,
  busy,
}: {
  previous: string | null;
  next: string | null;
  onNavigate: (url: string) => void;
  busy?: boolean;
}) {
  if (!previous && !next) return null;

  return (
    <div className="flex justify-end gap-2">
      <Button
        size="sm"
        variant="secondary"
        disabled={!previous || busy}
        onClick={() => previous && onNavigate(previous)}
        className="gap-1 shadow-xs"
      >
        <ChevronLeft className="size-3.5" />
        <span>Previous</span>
      </Button>
      <Button
        size="sm"
        variant="secondary"
        disabled={!next || busy}
        onClick={() => next && onNavigate(next)}
        className="gap-1 shadow-xs"
      >
        <span>Next</span>
        <ChevronRight className="size-3.5" />
      </Button>
    </div>
  );
}
