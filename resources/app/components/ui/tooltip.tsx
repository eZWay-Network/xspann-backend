import { Tooltip as Primitive } from "@base-ui/react/tooltip";
import type { ReactElement, ReactNode } from "react";
export const TooltipProvider = Primitive.Provider;
export function Tooltip({
  children,
  content,
}: {
  children: ReactElement;
  content: ReactNode;
}) {
  return (
    <Primitive.Root>
      <Primitive.Trigger render={children} />
      <Primitive.Portal>
        <Primitive.Positioner sideOffset={8} className="z-50">
          <Primitive.Popup className="rounded-md bg-foreground px-2.5 py-1.5 text-xs text-card shadow-popover">
            {content}
          </Primitive.Popup>
        </Primitive.Positioner>
      </Primitive.Portal>
    </Primitive.Root>
  );
}
