import { Accordion as Primitive } from "@base-ui/react/accordion";
import { ChevronDown } from "lucide-react";
import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";
export const Accordion = Primitive.Root;
export const AccordionItem = Primitive.Item;
export function AccordionTrigger({
  className,
  children,
  ...props
}: Omit<ComponentProps<typeof Primitive.Trigger>, "className"> & {
  className?: string;
}) {
  return (
    <Primitive.Header>
      <Primitive.Trigger className={cn("nav-link group", className)} {...props}>
        {children}
        <ChevronDown className="ml-auto size-3.5 transition-transform group-data-panel-open:rotate-180" />
      </Primitive.Trigger>
    </Primitive.Header>
  );
}
export function AccordionContent({
  className,
  ...props
}: Omit<ComponentProps<typeof Primitive.Panel>, "className"> & {
  className?: string;
}) {
  return (
    <Primitive.Panel className={cn("overflow-hidden", className)} {...props} />
  );
}
