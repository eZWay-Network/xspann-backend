import { PageHeader } from "@/components/page-header";
import {
  ReportFilters,
  type ReportRange,
} from "@/components/staff/report-filters";
import { ExportButton } from "@/components/staff/export-button";
import {
  FinanceSummary,
  type FinanceReport,
} from "@/components/staff/finance-summary";
import { useApp } from "@/contexts/app";
export default function Reports({
  report,
  filters,
  filter_url,
  export_url,
}: {
  report: FinanceReport;
  filters: ReportRange;
  filter_url: string;
  export_url: string | null;
}) {
  const { formatDateTime } = useApp();
  return (
    <div className="page-stack">
      <PageHeader
        title="Finance reports"
        description="Provider-backed billing totals and current paid access."
        actions={
          <>
            <ReportFilters filters={filters} url={filter_url} />
            {export_url && (
              <ExportButton url={export_url} type="finance" filters={filters} />
            )}
          </>
        }
      />
      <FinanceSummary report={report} />
      <p className="text-xs text-muted-foreground">
        Updated {formatDateTime(report.generated_at)} ({filters.timezone}) ·
        Earliest retained invoice:{" "}
        {report.data_since ? formatDateTime(report.data_since) : "none"}
      </p>
    </div>
  );
}
