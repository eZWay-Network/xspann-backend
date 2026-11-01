import { router } from "@inertiajs/react";
import { useState } from "react";
import { PageHeader } from "@/components/page-header";
import { Select } from "@/components/ui/select";
import { DateRange } from "@/components/ui/date-range";
import { OverviewMetrics } from "@/components/dashboard/overview-metrics";
import { ActivityChart } from "@/components/dashboard/activity-chart";
import { Distribution } from "@/components/dashboard/distribution";
import { AttentionRecords } from "@/components/dashboard/attention-records";
import { useApp } from "@/contexts/app";
import type { OverviewData } from "@/types";
export default function Overview({
  overview,
  filters,
  filter_url,
}: {
  overview: OverviewData;
  filters: { period: string; from: string; to: string; timezone: string };
  filter_url: string;
}) {
  const [loading, setLoading] = useState(false);
  const { auth, environment, preferences, formatDateTime } = useApp();
  const apply = (data: { period: string; from?: string; to?: string }) =>
    router.get(filter_url, data, {
      preserveScroll: true,
      preserveState: true,
      onStart: () => setLoading(true),
      onFinish: () => setLoading(false),
    });
  return (
    <div className="page-stack" aria-busy={loading}>
      <PageHeader
        title="Workspace overview"
        description={`Welcome back, ${auth!.user.name}. Here’s what’s happening across your workspace.`}
        actions={
          <>
            <Select
              label="Reporting period"
              value={filters.period}
              disabled={loading}
              onValueChange={(period) => {
                if (period !== "custom") apply({ period });
              }}
              options={[
                { value: "month", label: "This month" },
                { value: "7", label: "Last 7 days" },
                { value: "30", label: "Last 30 days" },
                { value: "90", label: "Last 90 days" },
                ...(filters.period === "custom"
                  ? [{ value: "custom", label: "Custom dates" }]
                  : []),
              ]}
            />
            <DateRange
              from={filters.from}
              to={filters.to}
              disabled={loading}
              onChange={(from, to) => apply({ period: "custom", from, to })}
            />
          </>
        }
      />
      <OverviewMetrics metrics={overview.metrics} />
      <div className="grid items-stretch gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
        <ActivityChart chart={overview.chart} />
        <Distribution distribution={overview.distribution} />
      </div>
      <AttentionRecords />
      <div className="flex flex-wrap justify-between gap-2 text-[11px] leading-5 text-muted-foreground">
        <p>{overview.comparison_note}</p>
        <p>
          Updated {formatDateTime(overview.generated_at)} ·{" "}
          {preferences.timezone} · {environment.billing} billing environment
        </p>
      </div>
    </div>
  );
}
