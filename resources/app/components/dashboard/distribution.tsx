import { PieChart } from "lucide-react";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardContent,
} from "@/components/ui/card";
import type { OverviewData } from "@/types";

const PALETTE = [
  "var(--primary)",
  "var(--chart-2)",
  "#6366f1",
  "#0ea5e9",
  "#10b981",
  "#f59e0b",
];

export function Distribution({
  distribution,
}: {
  distribution: OverviewData["distribution"];
}) {
  // Build dynamic conic gradient stops for any number of distribution segments
  let cumulative = 0;
  const gradientStops = distribution.items
    .map((item, idx) => {
      const start = cumulative;
      const end = cumulative + item.percent;
      cumulative = end;
      const color = PALETTE[idx % PALETTE.length];
      return `${color} ${start}% ${end}%`;
    })
    .join(", ");

  const conicBackground =
    distribution.total && gradientStops.length
      ? `conic-gradient(${gradientStops})`
      : "var(--border)";

  return (
    <Card className="flex flex-col justify-between overflow-hidden">
      <div>
        <CardHeader className="border-b border-border/70 pb-4">
          <div>
            <div className="flex items-center gap-2">
              <CardTitle className="text-base font-semibold">
                {distribution.title}
              </CardTitle>
            </div>
            <CardDescription className="mt-0.5 text-xs text-muted-foreground">
              Proportional distribution across your authorized workspace scope.
            </CardDescription>
          </div>
        </CardHeader>

        <CardContent className="pt-6">
          {/* Ring visualizer & total overview */}
          <div className="mb-7 flex items-center gap-6 rounded-xl border border-border/60 bg-muted/20 p-4">
            <div
              role="img"
              aria-label={distribution.items
                .map(
                  (item) => `${item.label}: ${item.value} (${item.percent}%)`,
                )
                .join(", ")}
              className="relative size-28 shrink-0 rounded-full shadow-xs transition-transform hover:scale-102"
              style={{ background: conicBackground }}
            >
              <div className="absolute inset-3 flex flex-col items-center justify-center rounded-full bg-card shadow-inner">
                <span className="text-base font-bold tracking-tight text-foreground tabular-nums">
                  {distribution.total.toLocaleString()}
                </span>
                <span className="text-[9px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Total
                </span>
              </div>
            </div>

            <div className="min-w-0 flex-1">
              <p className="text-xs font-semibold text-foreground">
                Category Composition
              </p>
              <p className="mt-1 text-xs text-muted-foreground leading-relaxed">
                Snapshot of active categorized records. Proportions update
                according to the selected range.
              </p>
            </div>
          </div>

          {/* Breakdown progress items */}
          <div className="space-y-4">
            {distribution.items.map((item, index) => {
              const color = PALETTE[index % PALETTE.length];

              return (
                <div key={item.label} className="group/item">
                  <div className="mb-1.5 flex items-center justify-between text-xs">
                    <div className="flex items-center gap-2">
                      <span
                        className="size-2 rounded-full shrink-0"
                        style={{ backgroundColor: color }}
                      />
                      <span className="font-medium text-foreground/90">
                        {item.label}
                      </span>
                    </div>

                    <div className="flex items-center gap-2 tabular-nums">
                      <span className="font-semibold text-foreground">
                        {item.value.toLocaleString()}
                      </span>
                      <span className="rounded bg-muted px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                        {item.percent}%
                      </span>
                    </div>
                  </div>

                  <div className="relative h-2 w-full overflow-hidden rounded-full bg-muted">
                    <div
                      className="h-full rounded-full transition-all duration-500"
                      style={{
                        width: `${item.percent}%`,
                        backgroundColor: color,
                      }}
                    />
                  </div>
                </div>
              );
            })}
          </div>

          {!distribution.total && (
            <div className="mt-6 flex flex-col items-center justify-center py-6 text-center text-muted-foreground">
              <PieChart className="size-7 opacity-40 mb-1.5" />
              <p className="text-xs">
                No records categorized in this current scope.
              </p>
            </div>
          )}
        </CardContent>
      </div>

      <div className="border-t border-border/60 bg-muted/20 px-5 py-3 sm:px-6">
        <p className="text-[11px] text-muted-foreground">
          Live breakdown reflecting {distribution.items.length} category
          segments.
        </p>
      </div>
    </Card>
  );
}
