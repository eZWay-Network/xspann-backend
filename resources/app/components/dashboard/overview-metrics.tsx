import type { Metric } from "@/types";
import { icons } from "@/lib/icons";

export function OverviewMetrics({ metrics }: { metrics: Metric[] }) {
  return (
    <section
      aria-label="Current workspace metrics"
      className="grid grid-cols-2 gap-3.5 sm:gap-4 xl:grid-cols-4"
    >
      {metrics.map((metric) => {
        const Icon = icons[metric.icon];

        return (
          <div
            key={metric.label}
            className="group focus-ring relative flex flex-col justify-between overflow-hidden rounded-xl border border-border/80 bg-card p-4.5 shadow-card transition-all hover:border-primary/40 hover:shadow-md sm:p-5"
          >
            <div>
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold tracking-tight text-muted-foreground">
                  {metric.label}
                </span>
                <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary transition-transform group-hover:scale-105">
                  <Icon className="size-4" strokeWidth={1.8} />
                </div>
              </div>

              <div className="mt-4 flex items-baseline gap-2">
                <p className="text-2xl font-bold tracking-tight text-foreground tabular-nums sm:text-3xl">
                  {metric.value.toLocaleString()}
                </p>
              </div>
            </div>

            <div className="mt-3.5 flex items-center gap-2 border-t border-border/50 pt-3 text-[11px] leading-tight text-muted-foreground">
              <span className="size-1.5 shrink-0 rounded-full bg-primary/60" />
              <span className="truncate">{metric.detail}</span>
            </div>
          </div>
        );
      })}
    </section>
  );
}
