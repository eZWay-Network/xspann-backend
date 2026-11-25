import { useLayoutEffect, useRef, useState } from "react";
import { format } from "date-fns";
import { BarChart3, Table as TableIcon, Calendar } from "lucide-react";
import { Tooltip } from "@/components/ui/tooltip";
import { useApp } from "@/contexts/app";
import { calendarDate } from "@/lib/staff-dates";
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
  const {
    preferences: { timezone },
  } = useApp();
  const plot = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(0);
  const [table, setTable] = useState(false);
  const available =
    chart.availability === "available" && chart.series.length > 0;

  useLayoutEffect(() => {
    const node = plot.current;
    if (!node) return;
    const resize = () => setWidth(node.clientWidth);
    resize();
    const observer = new ResizeObserver(resize);
    observer.observe(node);
    return () => observer.disconnect();
  }, [table, available]);

  const capacity = Math.max(1, Math.floor(width / 14));
  const days =
    chart.series.length <= capacity
      ? 1
      : 7 * Math.ceil(chart.series.length / (7 * capacity));

  const periods: {
    start: string;
    end: string;
    label: string;
    value: number;
  }[] = [];
  for (let index = 0; index < chart.series.length; index += days) {
    const rows = chart.series.slice(index, index + days);
    const start = rows[0]!.date;
    const end = rows[rows.length - 1]!.date;
    periods.push({
      start,
      end,
      label: start === end ? start : `${start} – ${end}`,
      value: rows.reduce((sum, row) => sum + row.value, 0),
    });
  }

  const maxPeriodValue = Math.max(0, ...periods.map((p) => p.value));
  const scale = Math.max(4, 4 * Math.ceil(maxPeriodValue / 4));
  const interval =
    days === 1 ? "Daily records" : `Aggregated per ${days}-day interval`;

  return (
    <Card className="flex flex-col justify-between overflow-hidden">
      <div>
        <CardHeader className="flex-row items-center justify-between border-b border-border/70 pb-4">
          <div>
            <div className="flex items-center gap-2">
              <CardTitle className="text-base font-semibold">
                {chart.title}
              </CardTitle>
            </div>
            <CardDescription className="mt-0.5 text-xs text-muted-foreground">
              {table ? "Data records breakdown" : interval} · {timezone}
            </CardDescription>
          </div>

          <Button
            size="sm"
            variant="secondary"
            onClick={() => setTable((value) => !value)}
            disabled={!available}
            aria-expanded={table}
            className="gap-1.5 text-xs font-medium"
          >
            {table ? (
              <>
                <BarChart3 className="size-3.5" />
                Show Chart
              </>
            ) : (
              <>
                <TableIcon className="size-3.5" />
                View Data
              </>
            )}
          </Button>
        </CardHeader>

        <CardContent className="pt-6">
          {/* Summary Metric Header */}
          <div className="mb-7 flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
                Total in Selected Range
              </p>
              <p className="mt-1 text-3xl font-bold tracking-tight text-foreground tabular-nums sm:text-4xl">
                {available ? chart.total.toLocaleString() : "—"}
              </p>
            </div>

            <div className="flex items-center gap-2 rounded-full border border-border bg-muted/50 px-3 py-1 text-xs font-medium text-muted-foreground">
              <span className="size-2 rounded-full bg-primary" />
              <span>{chart.unit || "Records"}</span>
            </div>
          </div>

          {!available ? (
            <div className="flex h-52 flex-col items-center justify-center rounded-lg border border-dashed border-border p-6 text-center text-muted-foreground">
              <Calendar className="size-8 opacity-40 mb-2" />
              <p className="text-sm font-medium">Data unavailable</p>
              <p className="text-xs">
                No records available for the current filter range.
              </p>
            </div>
          ) : table ? (
            <div className="max-h-56 overflow-y-auto rounded-lg border border-border">
              <Table>
                <caption className="sr-only">
                  {chart.title} by date in {timezone}
                </caption>
                <thead className="sticky top-0 bg-muted/80 backdrop-blur-xs">
                  <tr>
                    <TableHead>Date</TableHead>
                    <TableHead className="text-right">Count</TableHead>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/60">
                  {chart.series.map((row) => (
                    <tr
                      key={row.date}
                      className="hover:bg-muted/40 transition-colors"
                    >
                      <TableCell className="font-mono text-xs">
                        {row.date}
                      </TableCell>
                      <TableCell className="text-right font-medium tabular-nums">
                        {row.value.toLocaleString()}
                      </TableCell>
                    </tr>
                  ))}
                </tbody>
              </Table>
            </div>
          ) : (
            <div
              className="focus-ring flex h-52 gap-3 sm:h-56"
              tabIndex={0}
              role="img"
              aria-label={`${chart.title}. ${interval} in ${timezone}. Total ${chart.total}. Use View data for exact daily counts.`}
            >
              {/* Y-Axis scale indicators */}
              <div
                className="flex w-8 shrink-0 flex-col justify-between pb-7 text-[10px] font-mono text-muted-foreground"
                aria-hidden="true"
              >
                {[1, 0.75, 0.5, 0.25, 0].map((ratio) => (
                  <span key={ratio} className="leading-none">
                    {Math.round(scale * ratio)}
                  </span>
                ))}
              </div>

              {/* Main Bars container */}
              <div className="flex min-w-0 flex-1 flex-col">
                <div ref={plot} className="relative min-h-0 flex-1">
                  {/* Subtle Gridlines */}
                  <div
                    className="absolute inset-0 flex flex-col justify-between"
                    aria-hidden="true"
                  >
                    {[0, 1, 2, 3, 4].map((line) => (
                      <div
                        key={line}
                        className="w-full border-t border-dashed border-border/70"
                      />
                    ))}
                  </div>

                  {/* Rendered Bars */}
                  <div
                    className="relative flex h-full items-end justify-around"
                    style={{
                      columnGap: `min(8px, ${30 / Math.max(1, periods.length)}%)`,
                    }}
                  >
                    {periods.map((row, index) => {
                      const isLatest = index === periods.length - 1;
                      const heightPercent = (row.value / scale) * 100;

                      return (
                        <Tooltip
                          key={row.start}
                          content={`${row.label} · ${row.value.toLocaleString()} records`}
                        >
                          <div
                            tabIndex={-1}
                            aria-label={`${row.label} · ${row.value} records`}
                            className={`group/bar focus-ring relative min-w-1.5 flex-1 max-w-9 rounded-t-md transition-all hover:opacity-100 ${
                              isLatest
                                ? "bg-primary shadow-xs"
                                : "bg-primary/70 hover:bg-primary"
                            }`}
                            style={{
                              height: `${heightPercent}%`,
                              minHeight: row.value > 0 ? "4px" : "1px",
                            }}
                          />
                        </Tooltip>
                      );
                    })}
                  </div>
                </div>

                {/* X-Axis dates */}
                <div className="flex justify-between border-t border-border/80 pt-3 text-[11px] font-medium text-muted-foreground">
                  {[
                    ...new Set([
                      chart.series[0]!.date,
                      chart.series[Math.floor(chart.series.length / 2)]!.date,
                      chart.series[chart.series.length - 1]!.date,
                    ]),
                  ].map((date) => (
                    <span key={date}>
                      {format(calendarDate(date), "MMM d")}
                    </span>
                  ))}
                </div>
              </div>
            </div>
          )}

          {available && chart.total === 0 && (
            <p className="mt-3 text-xs text-muted-foreground">
              No activity records logged in this timeframe.
            </p>
          )}
        </CardContent>
      </div>

      <div className="border-t border-border/60 bg-muted/20 px-5 py-3 sm:px-6">
        <p className="text-[11px] leading-relaxed text-muted-foreground">
          {chart.definition}
        </p>
      </div>
    </Card>
  );
}
