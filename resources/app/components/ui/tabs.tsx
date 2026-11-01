import { Tabs as Primitive } from "@base-ui/react/tabs";
import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";
export const Tabs = Primitive.Root;
export const TabsPanel = Primitive.Panel;
export function TabsList({
  className,
  ...props
}: Omit<ComponentProps<typeof Primitive.List>, "className"> & {
  className?: string;
}) {
  return (
    <Primitive.List
      className={cn("inline-flex gap-1 rounded-md bg-muted p-1", className)}
      {...props}
    />
  );
}
export function TabsTrigger({
  className,
  ...props
}: Omit<ComponentProps<typeof Primitive.Tab>, "className"> & {
  className?: string;
}) {
  return (
    <Primitive.Tab
      className={cn(
        "focus-ring rounded px-3 py-1.5 text-xs font-medium text-muted-foreground transition-colors data-active:bg-card data-active:text-foreground data-active:shadow-card",
        className,
      )}
      {...props}
    />
  );
}
