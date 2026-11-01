import { ReportFilters } from "@/components/staff/report-filters";
import { ExportButton } from "@/components/staff/export-button";
import {
  FinanceSummary,
  type FinanceReport,
} from "@/components/staff/finance-summary";
import { PageHeader } from "@/components/page-header";
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
  finance,
  export_url,
}: {
  overview: OverviewData;
  filters: { period: string; from: string; to: string; timezone: string };
  filter_url: string;
  finance: FinanceReport | null;
  export_url: string | null;
}) {
  const { auth, environment, preferences, formatDateTime, workspace } =
    useApp();
  return (
    <div className="page-stack">
      <PageHeader
        title="Workspace overview"
        description={`Welcome back, ${auth!.user.name}. Here’s what’s happening in ${workspace.name}.`}
        actions={
          <>
            <ReportFilters filters={filters} url={filter_url} />
            {export_url && (
              <ExportButton
                url={export_url}
                type="dashboard"
                filters={filters}
              />
            )}
          </>
        }
      />
      <OverviewMetrics metrics={overview.metrics} />
      <div className="grid items-stretch gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
        <ActivityChart chart={overview.chart} />
        <Distribution distribution={overview.distribution} />
      </div>
      {finance && <FinanceSummary report={finance} />}
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
