import { Link } from "@inertiajs/react";
import { ArrowLeft, Clock } from "lucide-react";
import { ReportFilters } from "@/components/staff/report-filters";
import { ExportButton } from "@/components/staff/export-button";
import {
  FinanceSummary,
  type FinanceReport,
} from "@/components/staff/finance-summary";
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
  const { environment, preferences, formatDateTime, workspace } = useApp();

  return (
    <div className="space-y-8">
      {/* Header with Navigation and Filters */}
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <div className="flex items-center gap-2">
            <Link
              href="/"
              className="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors"
            >
              <ArrowLeft className="size-3.5" />
              Back to Moderation
            </Link>
          </div>
          <h1 className="mt-1 text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
            Workspace Overview & Analytics
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Performance analytics, activity trends, and platform volume for{" "}
            <span className="font-medium text-foreground">
              {workspace.name}
            </span>
            .
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
          <ReportFilters filters={filters} url={filter_url} />
          {export_url && (
            <ExportButton url={export_url} type="dashboard" filters={filters} />
          )}
        </div>
      </div>

      {/* KPI Metrics Grid */}
      <OverviewMetrics metrics={overview.metrics} />

      {/* Primary Analytics Charts Grid */}
      <div className="grid items-stretch gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)] 2xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
        <ActivityChart chart={overview.chart} />
        <Distribution distribution={overview.distribution} />
      </div>

      {/* Optional Finance Summary */}
      {finance && <FinanceSummary report={finance} />}

      {/* Actionable Staff Attention Items */}
      <AttentionRecords />

      {/* Metadata & Status Footer */}
      <div className="flex flex-col gap-2 rounded-xl border border-border/70 bg-card p-4 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-2">
          <Clock className="size-3.5 text-muted-foreground/70" />
          <span>{overview.comparison_note}</span>
        </div>
        <div className="flex flex-wrap items-center gap-2 text-[11px]">
          <span className="rounded bg-muted px-2 py-0.5 font-medium">
            Updated {formatDateTime(overview.generated_at)}
          </span>
          <span className="rounded bg-muted px-2 py-0.5 font-medium">
            {preferences.timezone}
          </span>
          <span className="rounded bg-muted px-2 py-0.5 font-medium capitalize">
            {environment.billing} billing
          </span>
        </div>
      </div>
    </div>
  );
}
