import { Field as Primitive } from "@base-ui/react/field";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

type Props = {
  label: string;
  name?: string;
  description?: string;
  error?: string;
  children: ReactNode;
  className?: string;
};
export function Field({
  label,
  name,
  description,
  error,
  children,
  className,
}: Props) {
  return (
    <Primitive.Root
      name={name}
      invalid={Boolean(error)}
      className={cn("space-y-2", className)}
    >
      <Primitive.Label className="block text-sm font-medium">
        {label}
      </Primitive.Label>
      {children}
      {description && (
        <Primitive.Description className="text-xs leading-5 text-muted-foreground">
          {description}
        </Primitive.Description>
      )}
      {error && (
        <Primitive.Error match className="block text-xs text-destructive">
          {error}
        </Primitive.Error>
      )}
    </Primitive.Root>
  );
}
