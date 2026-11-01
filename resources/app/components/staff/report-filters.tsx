import { router } from "@inertiajs/react";
import { useState } from "react";
import { Select } from "@/components/ui/select";
import { DateRange } from "@/components/ui/date-range";
export type ReportRange = {
  period: string;
  from: string;
  to: string;
  timezone: string;
};
export function ReportFilters({
  filters,
  url,
}: {
  filters: ReportRange;
  url: string;
}) {
  const [busy, setBusy] = useState(false);
  const apply = (range: { period: string; from?: string; to?: string }) =>
    router.get(url, range, {
      preserveScroll: true,
      preserveState: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
    });
  return (
    <>
      <Select
        label="Reporting period"
        value={filters.period}
        disabled={busy}
        onValueChange={(period) => {
          if (period !== "custom") apply({ period });
        }}
        options={[
          { value: "month", label: "This month" },
          ...["7", "30", "90"].map((value) => ({
            value,
            label: `Last ${value} days`,
          })),
          ...(filters.period === "custom"
            ? [{ value: "custom", label: "Custom dates" }]
            : []),
        ]}
      />
      <DateRange
        from={filters.from}
        to={filters.to}
        disabled={busy}
        onChange={(from, to) => apply({ period: "custom", from, to })}
      />
    </>
  );
}
