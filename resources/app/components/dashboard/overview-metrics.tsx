import type { Metric } from "@/types";
import { icons } from "@/lib/icons";
export function OverviewMetrics({ metrics }: { metrics: Metric[] }) {
  return (
    <section
      aria-label="Current workspace metrics"
      className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4"
    >
      {metrics.map((metric) => {
        const Icon = icons[metric.icon];
        return (
          <div key={metric.label} className="surface p-4 sm:p-5">
            <div className="flex items-center justify-between gap-2">
              <span className="text-xs font-medium text-muted-foreground">
                {metric.label}
              </span>
              <Icon
                className="hidden size-4 text-muted-foreground/70 sm:block"
                strokeWidth={1.6}
              />
            </div>
            <div className="mt-5 flex items-end justify-between gap-3">
              <p className="text-2xl font-semibold tracking-tight tabular-nums sm:text-[28px]">
                {metric.value.toLocaleString()}
              </p>
            </div>
            <div className="mt-3 text-[11px] leading-5 text-muted-foreground">
              {metric.detail}
            </div>
          </div>
        );
      })}
    </section>
  );
}
