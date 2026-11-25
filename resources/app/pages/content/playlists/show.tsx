import { Link, router } from "@inertiajs/react";
import { useState } from "react";
import { Music2, ArrowUpRight, ListMusic } from "lucide-react";
import {
  DetailScreen,
  type DetailProps,
  type RecordLink,
} from "@/components/staff/detail";
import { RecordsPagination } from "@/components/records-pagination";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

type Props = DetailProps & {
  items: {
    rows: RecordLink[];
    pagination: { current_page: number; last_page: number; total: number };
    per_page: number;
    url: string;
  };
};

export default function Playlist({ items, ...props }: Props) {
  const [busy, setBusy] = useState(false);

  return (
    <DetailScreen {...props}>
      <Card
        className="overflow-hidden border-border/80 shadow-card"
        aria-busy={busy}
      >
        <CardHeader className="border-b border-border/70 bg-muted/20 pb-4">
          <div className="flex items-center justify-between">
            <div>
              <div className="flex items-center gap-2">
                <ListMusic className="size-4.5 text-primary" />
                <CardTitle className="text-base font-semibold">
                  Playlist Tracklist & Sequence
                </CardTitle>
                <span className="rounded-full bg-muted px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                  {items.pagination.total} tracks
                </span>
              </div>
              <CardDescription className="text-xs text-muted-foreground mt-0.5">
                Ordered audio assets included in this playlist collection.
              </CardDescription>
            </div>
          </div>
        </CardHeader>

        <div className="p-5 sm:p-6 space-y-4">
          {items.rows.length ? (
            <div className="divide-y divide-border/60 rounded-xl border border-border/80 bg-card overflow-hidden">
              {items.rows.map((row, idx) => {
                const trackNumber =
                  (items.pagination.current_page - 1) * items.per_page +
                  idx +
                  1;

                return (
                  <div
                    key={row.url + idx}
                    className="flex items-center justify-between p-3.5 hover:bg-muted/30 transition-colors"
                  >
                    <div className="flex items-center gap-3.5 min-w-0 pr-4">
                      <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-xs font-mono font-semibold text-muted-foreground">
                        #{trackNumber}
                      </span>

                      <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-purple-500/10 text-purple-600">
                        <Music2 className="size-4" />
                      </div>

                      <span className="truncate text-sm font-medium text-foreground">
                        {row.label}
                      </span>
                    </div>

                    <Link
                      href={row.url}
                      className={cn(
                        buttonVariants({ variant: "secondary", size: "sm" }),
                        "gap-1 text-xs shrink-0 hover:border-primary/40 hover:text-primary transition-colors",
                      )}
                    >
                      <span>Inspect Audio</span>
                      <ArrowUpRight className="size-3.5 opacity-70" />
                    </Link>
                  </div>
                );
              })}
            </div>
          ) : (
            <div className="flex flex-col items-center justify-center py-10 text-center text-muted-foreground">
              <ListMusic className="size-8 opacity-30 mb-2" />
              <p className="text-sm font-medium">No tracks found</p>
              <p className="text-xs">
                This playlist currently contains no audio tracks.
              </p>
            </div>
          )}

          <RecordsPagination
            page={items.pagination.current_page}
            lastPage={items.pagination.last_page}
            total={items.pagination.total}
            busy={busy}
            onChange={(page) =>
              router.get(
                items.url,
                { page, per_page: items.per_page },
                {
                  preserveScroll: true,
                  onStart: () => setBusy(true),
                  onFinish: () => setBusy(false),
                },
              )
            }
          />
        </div>
      </Card>
    </DetailScreen>
  );
}
