import { Link } from "@inertiajs/react";
import { ArrowRight } from "lucide-react";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import { buttonVariants } from "@/components/ui/button";
import { AttentionItems } from "@/components/attention-drawer";
import { useApp } from "@/contexts/app";

export function AttentionRecords() {
  const { urls, attention } = useApp();
  const count = attention.length;

  return (
    <Card className="overflow-hidden">
      <CardHeader className="flex-row items-center justify-between border-b border-border/70 pb-4">
        <div>
          <div className="flex items-center gap-2">
            <CardTitle className="text-base font-semibold">
              Needs your attention
            </CardTitle>
            <span
              className={`rounded-full px-2 py-0.5 text-[11px] font-medium ${
                count > 0
                  ? "bg-warning-soft text-warning border border-warning/20"
                  : "bg-muted text-muted-foreground"
              }`}
            >
              {count > 0 ? `${count} pending` : "All clear"}
            </span>
          </div>
          <CardDescription className="mt-0.5 text-xs text-muted-foreground">
            Staff account notices and actionable alerts scoped to your access.
          </CardDescription>
        </div>

        <Link
          href={urls.attention}
          className={buttonVariants({ variant: "secondary", size: "sm" })}
        >
          View all
          <ArrowRight className="size-3.5" />
        </Link>
      </CardHeader>
      <AttentionItems />
    </Card>
  );
}
