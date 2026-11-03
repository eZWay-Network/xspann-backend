import { Card, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableHead, TableCell } from "@/components/ui/table";
import { Alert } from "@/components/ui/alert";
export type FinanceReport = {
  environment: string;
  definition: string;
  generated_at: string;
  data_since: string | null;
  invoices: { currency: string; count: number; amount_paid: number }[];
  adjustments: {
    currency: string;
    kind: string;
    status: string;
    allocation: string;
    count: number;
    amount: number;
  }[];
  paid_access: { plan: string; accounts: number }[];
  reconciliation_gaps: number;
};
function money(value: number, currency: string) {
  const formatter = new Intl.NumberFormat("en", {
    style: "currency",
    currency,
  });
  return formatter.format(
    value / 10 ** formatter.resolvedOptions().maximumFractionDigits!,
  );
}
export function FinanceSummary({ report }: { report: FinanceReport }) {
  return (
    <div className="space-y-6">
      <Alert variant="info">
        {report.definition} Environment: {report.environment}.
      </Alert>
      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Paid invoices issued</CardTitle>
          </CardHeader>
          {report.invoices.length ? (
            <Table>
              <thead>
                <tr>
                  <TableHead>Currency</TableHead>
                  <TableHead>Invoices</TableHead>
                  <TableHead>Tax-inclusive paid amount</TableHead>
                </tr>
              </thead>
              <tbody>
                {report.invoices.map((row) => (
                  <tr key={row.currency}>
                    <TableCell>{row.currency}</TableCell>
                    <TableCell>{row.count}</TableCell>
                    <TableCell>
                      {money(row.amount_paid, row.currency)}
                    </TableCell>
                  </tr>
                ))}
              </tbody>
            </Table>
          ) : (
            <p className="p-6 text-sm text-muted-foreground">
              No paid invoices were issued in this range.
            </p>
          )}
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Current paid access</CardTitle>
          </CardHeader>
          <div className="space-y-3 p-6 pt-0">
            {report.paid_access.map((row) => (
              <div
                className="flex justify-between gap-4 text-sm"
                key={row.plan}
              >
                <span>{row.plan.replaceAll("_", " ")}</span>
                <strong>{row.accounts}</strong>
              </div>
            ))}
            {!report.paid_access.length && (
              <p className="text-sm text-muted-foreground">
                No accounts currently have paid access.
              </p>
            )}
            <p className="border-t pt-4 text-xs text-muted-foreground">
              {report.reconciliation_gaps} subscriptions have no reconciliation
              within the last 24 hours. Cancellation alone does not end paid
              access.
            </p>
          </div>
        </Card>
      </div>
      <Card>
        <CardHeader>
          <CardTitle>Adjustments created in range · current state</CardTitle>
        </CardHeader>
        {report.adjustments.length ? (
          <Table>
            <thead>
              <tr>
                {["Kind", "Status", "Allocation", "Records", "Amount"].map(
                  (label) => (
                    <TableHead key={label}>{label}</TableHead>
                  ),
                )}
              </tr>
            </thead>
            <tbody>
              {report.adjustments.map((row, i) => (
                <tr key={i}>
                  <TableCell>{row.kind}</TableCell>
                  <TableCell>{row.status.replaceAll("_", " ")}</TableCell>
                  <TableCell>{row.allocation.replaceAll("_", " ")}</TableCell>
                  <TableCell>{row.count}</TableCell>
                  <TableCell>{money(row.amount, row.currency)}</TableCell>
                </tr>
              ))}
            </tbody>
          </Table>
        ) : (
          <p className="p-6 text-sm text-muted-foreground">
            No adjustments were created in this range.
          </p>
        )}
      </Card>
    </div>
  );
}
