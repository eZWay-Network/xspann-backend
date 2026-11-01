import { Checkbox as Primitive } from "@base-ui/react/checkbox";
import { Check } from "lucide-react";
import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";

export function Checkbox({
  className,
  ...props
}: Omit<ComponentProps<typeof Primitive.Root>, "className"> & {
  className?: string;
}) {
  return (
    <Primitive.Root
      className={cn(
        "focus-ring mt-0.5 inline-flex size-4.5 shrink-0 items-center justify-center rounded border border-input bg-card shadow-card transition-colors hover:border-primary/50 data-checked:border-primary data-checked:bg-primary data-checked:text-primary-foreground data-disabled:cursor-not-allowed data-disabled:opacity-50 aria-invalid:border-destructive",
        className,
      )}
      {...props}
    >
      <Primitive.Indicator>
        <Check className="size-3.5" strokeWidth={3} />
      </Primitive.Indicator>
    </Primitive.Root>
  );
}
