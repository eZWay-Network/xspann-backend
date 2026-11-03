import { Link } from "@inertiajs/react";
import { Card, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableHead, TableCell } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Alert } from "@/components/ui/alert";
import { PageHeader } from "@/components/page-header";
import { useApp } from "@/contexts/app";
import { ExportButton } from "@/components/staff/export-button";
export type Worker = {
  task: string;
  status: string;
  started_at: string | null;
  succeeded_at: string | null;
  duration_ms: number | null;
};
type Health = {
  driver: string;
  queue_availability: string;
  generated_at: string;
  workers: Worker[];
  queues: {
    queue: string;
    pending: number;
    processing: number;
    failed: number;
    oldest_at: string | null;
  }[];
  domains: {
    label: string;
    count: number;
    oldest_at: string | null;
    url: string;
  }[];
};
export function WorkerTable({ workers }: { workers: Worker[] }) {
  const { formatDateTime } = useApp();
  return (
    <Card>
      <CardHeader>
        <CardTitle>Recurring worker observations</CardTitle>
      </CardHeader>
      <Table>
        <thead>
          <tr>
            {["Task", "State", "Last start", "Last success", "Duration"].map(
              (value) => (
                <TableHead key={value}>{value}</TableHead>
              ),
            )}
          </tr>
        </thead>
        <tbody>
          {workers.map((worker) => (
            <tr key={worker.task}>
              <TableCell>{worker.task}</TableCell>
              <TableCell>
                <Badge>{worker.status}</Badge>
              </TableCell>
              <TableCell>
                {worker.started_at
                  ? formatDateTime(worker.started_at)
                  : "Unknown"}
              </TableCell>
              <TableCell>
                {worker.succeeded_at
                  ? formatDateTime(worker.succeeded_at)
                  : "Unknown"}
              </TableCell>
              <TableCell>
                {worker.duration_ms === null ? "—" : `${worker.duration_ms} ms`}
              </TableCell>
            </tr>
          ))}
        </tbody>
      </Table>
    </Card>
  );
}
export function OperationalHealth({ health }: { health: Health }) {
  const { page, formatDateTime, auth, preferences } = useApp();
  return (
    <div className="page-stack">
      <PageHeader
        title={page.title}
        description="Queue metadata, domain backlog and observed maintenance runs."
        actions={
          auth?.permissions.includes("exports.create") ? (
            <ExportButton url="/exports" type="operations" />
          ) : undefined
        }
      />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {health.domains.map((domain) => (
          <Card key={domain.label} className="p-5">
            <Link
              className="text-sm text-muted-foreground hover:text-primary"
              href={domain.url}
            >
              {domain.label}
            </Link>
            <p className="mt-3 text-3xl font-semibold">{domain.count}</p>
            <p className="mt-3 text-xs text-muted-foreground">
              Oldest:{" "}
              {domain.oldest_at
                ? formatDateTime(domain.oldest_at)
                : "No pending records"}
            </p>
          </Card>
        ))}
      </div>
      <WorkerTable workers={health.workers} />
      <Alert variant="info">
        A recorded run shows that maintenance executed. It does not verify every
        downstream provider or prove all queued work succeeded.
      </Alert>
      {health.queue_availability !== "available" ? (
        <Alert>
          Queue metadata is unavailable for the configured {health.driver}{" "}
          driver. Domain backlog and worker observations remain available.
        </Alert>
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>Queues · {health.driver}</CardTitle>
          </CardHeader>
          <Table>
            <thead>
              <tr>
                {[
                  "Queue",
                  "Pending / scheduled",
                  "Processing",
                  "Failed",
                  "Oldest scheduled time",
                ].map((value) => (
                  <TableHead key={value}>{value}</TableHead>
                ))}
              </tr>
            </thead>
            <tbody>
              {health.queues.map((queue) => (
                <tr key={queue.queue}>
                  <TableCell>{queue.queue}</TableCell>
                  <TableCell>{queue.pending}</TableCell>
                  <TableCell>{queue.processing}</TableCell>
                  <TableCell>{queue.failed}</TableCell>
                  <TableCell>
                    {queue.oldest_at ? formatDateTime(queue.oldest_at) : "—"}
                  </TableCell>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}
      <p className="text-xs text-muted-foreground">
        Observed {formatDateTime(health.generated_at)} ({preferences.timezone}).
        Use the corresponding domain page to recover eligible work.
      </p>
    </div>
  );
}
