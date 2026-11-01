import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";
export function Table({ className, ...props }: ComponentProps<"table">) {
  return (
    <div className="relative overflow-x-auto">
      <table
        className={cn(
          "w-full text-left text-sm whitespace-nowrap [&_tbody_tr:last-child_td]:border-b-0",
          className,
        )}
        {...props}
      />
    </div>
  );
}
export function TableHead({ className, ...props }: ComponentProps<"th">) {
  return (
    <th
      scope="col"
      className={cn(
        "border-y bg-muted/50 px-5 py-3.5 text-[11px] font-medium text-muted-foreground first:pl-6 last:pr-6",
        className,
      )}
      {...props}
    />
  );
}
export function TableCell({ className, ...props }: ComponentProps<"td">) {
  return (
    <td
      className={cn(
        "border-b border-border/80 px-5 py-4 text-[13px] first:pl-6 last:pr-6",
        className,
      )}
      {...props}
    />
  );
}
