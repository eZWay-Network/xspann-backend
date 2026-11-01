import { Avatar as Primitive } from "@base-ui/react/avatar";
import { cn, initials } from "@/lib/utils";
export function Avatar({
  name,
  src,
  className,
}: {
  name: string;
  src?: string;
  className?: string;
}) {
  return (
    <Primitive.Root
      className={cn(
        "inline-flex overflow-hidden size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-xs font-medium text-muted-foreground",
        className,
      )}
    >
      {src && (
        <Primitive.Image
          src={src}
          alt={name}
          className="size-full object-cover"
        />
      )}
      <Primitive.Fallback>{initials(name)}</Primitive.Fallback>
    </Primitive.Root>
  );
}
