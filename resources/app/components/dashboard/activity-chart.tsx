import { Tooltip } from "@/components/ui/tooltip";
import { useLayoutEffect, useRef, useState } from "react";
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

  const capacity = Math.max(1, Math.floor(width / 12));
  const days =
    chart.series.length <= capacity
      ? 1
      : 7 * Math.ceil(chart.series.length / (7 * capacity));
  const periods = [];
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
  const scale = Math.max(
    4,
    4 * Math.ceil(Math.max(0, ...periods.map((period) => period.value)) / 4),
  );
  const interval =
    days === 1 ? "Daily records" : `Records per ${days}-day period`;
  return (
    <Card>
      <CardHeader>
        <div>
          <CardTitle>{chart.title}</CardTitle>
          <CardDescription>
            {table ? "Daily records" : interval} · UTC
          </CardDescription>
        </div>
        <Button
          size="sm"
          variant="ghost"
          onClick={() => setTable((value) => !value)}
          disabled={!available}
          aria-expanded={table}
        >
          {table ? "Show chart" : "View data"}
        </Button>
      </CardHeader>
      <CardContent>
        <div className="mb-8 flex flex-wrap items-center justify-between gap-3">
          <strong className="text-3xl font-semibold tracking-tight tabular-nums">
            {available ? chart.total.toLocaleString() : "—"}
          </strong>
          <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <i className="size-2 rounded-sm bg-chart-1" />
            Records in range
          </span>
        </div>
        {!available ? (
          <p className="text-description">Data unavailable for this range.</p>
        ) : table ? (
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
            className="focus-ring flex h-48 gap-3 rounded sm:h-52"
            tabIndex={0}
            role="img"
            aria-label={`${chart.title}. ${interval} in UTC. Total ${chart.total}. Use View data for exact daily counts.`}
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
              <div ref={plot} className="relative min-h-0 flex-1">
                <div
                  className="absolute inset-0 flex flex-col justify-between"
                  aria-hidden="true"
                >
                  {[0, 1, 2, 3, 4].map((line) => (
                    <div key={line} className="border-t border-dashed" />
                  ))}
                </div>
                <div
                  className="relative flex h-full items-end justify-around"
                  style={{ columnGap: `min(6px, ${35 / periods.length}%)` }}
                >
                  {periods.map((row, index) => (
                    <Tooltip
                      key={row.start}
                      content={`${row.label} UTC · ${row.value} records`}
                    >
                      <div
                        tabIndex={-1}
                        aria-label={`${row.label} UTC · ${row.value} records`}
                        className={`focus-ring relative min-w-0 flex-1 max-w-10 rounded-t transition-colors hover:bg-chart-1 focus:bg-chart-1 ${index === periods.length - 1 ? "bg-chart-1" : "bg-chart-2"}`}
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
                {[
                  ...new Set([
                    chart.series[0]!.date,
                    chart.series[Math.floor(chart.series.length / 2)]!.date,
                    chart.series[chart.series.length - 1]!.date,
                  ]),
                ].map((date) => (
                  <span key={date}>{format(parseISO(date), "MMM d")}</span>
                ))}
              </div>
            </div>
          </div>
        )}
        {available && chart.total === 0 && (
          <p className="mt-3 text-sm text-muted-foreground">
            No records in this range.
          </p>
        )}
        <p className="mt-5 text-[11px] leading-5 text-muted-foreground">
          {chart.definition}
        </p>
      </CardContent>
    </Card>
  );
}
