import { cva, type VariantProps } from "class-variance-authority";
import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";
const variants = cva(
  "inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[11px] font-medium leading-5 whitespace-nowrap",
  {
    variants: {
      tone: {
        neutral: "border border-border bg-muted/50 text-muted-foreground",
        success: "bg-success-soft text-success",
        warning: "bg-warning-soft text-warning",
        info: "bg-accent text-accent-foreground",
        danger: "bg-destructive-soft text-destructive",
      },
    },
    defaultVariants: { tone: "neutral" },
  },
);
export function Badge({
  className,
  tone,
  ...props
}: ComponentProps<"span"> & VariantProps<typeof variants>) {
  return <span className={cn(variants({ tone }), className)} {...props} />;
}
