import { Input as Primitive } from "@base-ui/react/input";
import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";
export function Input({
  className,
  variant = "default",
  ...props
}: Omit<ComponentProps<typeof Primitive>, "className"> & {
  className?: string;
  variant?: "default" | "plain";
}) {
  return (
    <Primitive
      className={cn(
        variant === "plain"
          ? "h-14 min-w-0 w-full border-0 bg-transparent text-sm outline-none placeholder:text-muted-foreground/70 focus-visible:outline-none"
          : "field-control",
        className,
      )}
      {...props}
    />
  );
}
