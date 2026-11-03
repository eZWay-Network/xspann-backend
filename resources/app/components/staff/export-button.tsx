import { useForm } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import { FormErrors } from "@/components/form-errors";
import type { ReportRange } from "@/components/staff/report-filters";
export function ExportButton({
  url,
  type,
  filters,
}: {
  url: string;
  type: "dashboard" | "finance" | "operations";
  filters?: ReportRange;
}) {
  const form = useForm({
    type,
    period: filters?.period ?? "month",
    from: filters?.from,
    to: filters?.to,
    idempotency_key: crypto.randomUUID(),
  });
  return (
    <div>
      <Button
        variant="secondary"
        loading={form.processing}
        onClick={() => {
          form.transform((data) => ({
            ...data,
            type,
            timezone: filters?.timezone,
            period: filters?.period ?? "month",
            from: filters?.from,
            to: filters?.to,
          }));
          form.post(url);
        }}
      >
        Export report
      </Button>
      <FormErrors errors={form.errors} />
    </div>
  );
}
