import { Field as Primitive } from "@base-ui/react/field";
import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";

export function Textarea({
  className,
  rows = 4,
  ...props
}: Omit<ComponentProps<typeof Primitive.Control>, "className"> & {
  className?: string;
  rows?: number;
}) {
  return (
    <Primitive.Control
      render={<textarea rows={rows} />}
      className={cn(
        "field-control min-h-28 resize-y py-3 leading-relaxed",
        className,
      )}
      {...props}
    />
  );
}
