import { Menu } from "@base-ui/react/menu";
import type { ComponentProps } from "react";
import { cn } from "@/lib/utils";
export const DropdownMenu = Menu.Root;
export const DropdownMenuTrigger = Menu.Trigger;
export function DropdownMenuContent({
  children,
  className,
  ...props
}: Omit<ComponentProps<typeof Menu.Popup>, "className"> & {
  className?: string;
}) {
  return (
    <Menu.Portal>
      <Menu.Positioner align="end" sideOffset={8} className="z-50">
        <Menu.Popup
          className={cn(
            "min-w-52 rounded-lg border bg-card p-1.5 shadow-popover outline-none",
            className,
          )}
          {...props}
        >
          {children}
        </Menu.Popup>
      </Menu.Positioner>
    </Menu.Portal>
  );
}
export function DropdownMenuItem({
  className,
  ...props
}: Omit<ComponentProps<typeof Menu.Item>, "className"> & {
  className?: string;
}) {
  return <Menu.Item className={cn("menu-item", className)} {...props} />;
}
export function DropdownMenuSeparator() {
  return <Menu.Separator className="my-1 h-px bg-border" />;
}
