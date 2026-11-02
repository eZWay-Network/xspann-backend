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
      <p aria-live="polite">
        {total.toLocaleString()} {total === 1 ? "record" : "records"}{" "}
        <span className="mx-1.5">·</span> Page {page} of {Math.max(1, lastPage)}
      </p>
      <div className="flex gap-2">
        <Button
          size="sm"
          variant="secondary"
          disabled={page <= 1 || busy}
          onClick={() => onChange(page - 1)}
        >
          <ChevronLeft />
          Previous
        </Button>
        <Button
          size="sm"
          variant="secondary"
          disabled={page >= lastPage || busy}
          onClick={() => onChange(page + 1)}
        >
          Next
          <ChevronRight />
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
      >
        <ChevronLeft />
        Previous
      </Button>
      <Button
        size="sm"
        variant="secondary"
        disabled={!next || busy}
        onClick={() => next && onNavigate(next)}
      >
        Next
        <ChevronRight />
      </Button>
    </div>
  );
}
