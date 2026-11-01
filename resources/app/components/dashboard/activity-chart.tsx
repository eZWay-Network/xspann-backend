import { Tooltip } from "@/components/ui/tooltip";
import { useState } from "react";
import { format, parseISO } from "date-fns";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardContent,
} from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Table, TableHead, TableCell } from "@/components/ui/table";
import type { OverviewData } from "@/types";
export function ActivityChart({ chart }: { chart: OverviewData["chart"] }) {
  const scale = Math.max(4, Math.ceil(chart.ceiling / 4) * 4);
  const [table, setTable] = useState(false);
  return (
    <Card>
      <CardHeader>
        <div>
          <CardTitle>{chart.title}</CardTitle>
          <CardDescription>Daily records · aggregated in UTC</CardDescription>
        </div>
        <Button
          size="sm"
          variant="ghost"
          onClick={() => setTable((value) => !value)}
          aria-expanded={table}
        >
          {table ? "Show chart" : "View data"}
        </Button>
      </CardHeader>
      <CardContent>
        <div className="mb-8 flex flex-wrap items-center justify-between gap-3">
          <strong className="text-3xl font-semibold tracking-tight tabular-nums">
            {chart.total.toLocaleString()}
          </strong>
          <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <i className="size-2 rounded-sm bg-chart-1" />
            Records in range
          </span>
        </div>
        {table ? (
          <div className="max-h-52 overflow-y-auto">
            <Table>
              <caption className="sr-only">{chart.title} by UTC day</caption>
              <thead>
                <tr>
                  <TableHead>UTC date</TableHead>
                  <TableHead>Records</TableHead>
                </tr>
              </thead>
              <tbody>
                {chart.series.map((row) => (
                  <tr key={row.date}>
                    <TableCell>{row.date}</TableCell>
                    <TableCell>{row.value}</TableCell>
                  </tr>
                ))}
              </tbody>
            </Table>
          </div>
        ) : (
          <div
            className="flex h-48 gap-3 sm:h-52"
            role="group"
            aria-label={`${chart.title}. Total ${chart.total}. Use View data for a table.`}
          >
            <div
              className="flex w-9 shrink-0 flex-col justify-between pb-6 text-[10px] text-muted-foreground"
              aria-hidden="true"
            >
              {[1, 0.75, 0.5, 0.25, 0].map((ratio) => (
                <span key={ratio}>{scale * ratio}</span>
              ))}
            </div>
            <div className="flex min-w-0 flex-1 flex-col">
              <div className="relative min-h-0 flex-1">
                <div
                  className="absolute inset-0 flex flex-col justify-between"
                  aria-hidden="true"
                >
                  {[0, 1, 2, 3, 4].map((line) => (
                    <div key={line} className="border-t border-dashed" />
                  ))}
                </div>
                <div className="relative flex h-full items-end justify-around gap-0.5 px-1 sm:gap-1.5">
                  {chart.series.map((row, index) => (
                    <Tooltip
                      key={row.date}
                      content={`${row.date}: ${row.value} records`}
                    >
                      <div
                        tabIndex={0}
                        aria-label={`${row.date}: ${row.value} records`}
                        className={`focus-ring group relative w-full max-w-10 rounded-t transition-colors hover:bg-chart-1 focus:bg-chart-1 ${index === chart.series.length - 1 ? "bg-chart-1" : "bg-chart-1/30"}`}
                        style={{
                          height: `${(row.value / scale) * 100}%`,
                          minHeight: row.value > 0 ? "2px" : "0px",
                        }}
                      ></div>
                    </Tooltip>
                  ))}
                </div>
              </div>
              <div className="flex justify-between pt-3 text-[10px] text-muted-foreground">
                {chart.series
                  .filter(
                    (_, index) =>
                      index === 0 ||
                      index === chart.series.length - 1 ||
                      index === Math.floor(chart.series.length / 2),
                  )
                  .map((row) => (
                    <span key={row.date}>
                      {format(parseISO(row.date), "MMM d")}
                    </span>
                  ))}
              </div>
            </div>
          </div>
        )}
        <p className="mt-5 text-[11px] leading-5 text-muted-foreground">
          {chart.definition}
        </p>
      </CardContent>
    </Card>
  );
}
