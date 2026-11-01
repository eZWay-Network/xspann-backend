import { Activity } from "lucide-react";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardContent,
} from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import type { OverviewData } from "@/types";
export function Distribution({
  distribution,
}: {
  distribution: OverviewData["distribution"];
}) {
  const first = distribution.items[0]?.percent ?? 0;
  return (
    <Card>
      <CardHeader>
        <div>
          <CardTitle>{distribution.title}</CardTitle>
          <CardDescription>
            Current state across your permitted scope.
          </CardDescription>
        </div>
      </CardHeader>
      <CardContent>
        <div className="mb-7 flex flex-wrap items-center gap-5">
          <div
            role="img"
            aria-label={distribution.items
              .map((item) => `${item.label}: ${item.value} (${item.percent}%)`)
              .join(", ")}
            className="relative size-32 shrink-0 rounded-full"
            style={{
              background: distribution.total
                ? `conic-gradient(var(--chart-2) 0% ${first}%, var(--chart-1) ${first}% 100%)`
                : "var(--border)",
            }}
          >
            <div className="absolute inset-3.5 flex items-center justify-center rounded-full bg-card">
              <Activity
                className="size-7 text-muted-foreground/70"
                strokeWidth={1.5}
              />
            </div>
          </div>
          <div>
            <p className="text-xs text-muted-foreground">Current total</p>
            <p className="mt-1 text-2xl font-semibold tracking-tight">
              {distribution.total.toLocaleString()}
            </p>
            <p className="mt-2 text-[11px] text-muted-foreground">
              Snapshot now
            </p>
          </div>
        </div>
        <div className="space-y-4">
          {distribution.items.map((item, index) => (
            <div key={item.label}>
              <div className="mb-2 flex justify-between gap-4 text-xs">
                <span className="text-muted-foreground">{item.label}</span>
                <span className="font-medium tabular-nums">
                  {item.value.toLocaleString()}{" "}
                  <span className="ml-1 font-normal text-muted-foreground">
                    {item.percent}%
                  </span>
                </span>
              </div>
              <Progress
                value={item.percent}
                label={item.label}
                className={index === 0 ? "bg-chart-2" : "bg-chart-1"}
              />
            </div>
          ))}
        </div>
        {!distribution.total && (
          <p className="mt-4 text-xs text-muted-foreground">
            No records in this current scope.
          </p>
        )}
      </CardContent>
    </Card>
  );
}
