import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";
export function Card({ className, ...props }: ComponentProps<"section">) {
  return <section className={cn("surface", className)} {...props} />;
}
export function CardHeader({ className, ...props }: ComponentProps<"div">) {
  return (
    <div
      className={cn(
        "flex items-start justify-between gap-4 p-5 sm:p-6",
        className,
      )}
      {...props}
    />
  );
}
export function CardTitle({ className, ...props }: ComponentProps<"h2">) {
  return <h2 className={cn("section-title", className)} {...props} />;
}
export function CardDescription({ className, ...props }: ComponentProps<"p">) {
  return (
    <p
      className={cn("mt-1 text-xs leading-5 text-muted-foreground", className)}
      {...props}
    />
  );
}
export function CardContent({ className, ...props }: ComponentProps<"div">) {
  return (
    <div className={cn("px-5 pb-5 sm:px-6 sm:pb-6", className)} {...props} />
  );
}
export function CardFooter({ className, ...props }: ComponentProps<"div">) {
  return (
    <div
      className={cn(
        "flex items-center justify-between gap-4 rounded-b-lg border-t bg-muted/25 px-5 py-4 sm:px-6",
        className,
      )}
      {...props}
    />
  );
}
