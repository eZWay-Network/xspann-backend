import { Progress as Primitive } from "@base-ui/react/progress";
import { cn } from "@/lib/utils";
export function Progress({
  value,
  label,
  className,
}: {
  value: number;
  label: string;
  className?: string;
}) {
  return (
    <Primitive.Root value={value} aria-label={label}>
      <Primitive.Track className="h-1.5 overflow-hidden rounded-full bg-muted">
        <Primitive.Indicator
          className={cn(
            "h-full rounded-full bg-primary transition-[width]",
            className,
          )}
        />
      </Primitive.Track>
    </Primitive.Root>
  );
}
