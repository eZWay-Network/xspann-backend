import type { ComponentProps } from "react";
import { DialogContent } from "./dialog";
export {
  Dialog as Drawer,
  DialogTitle as DrawerTitle,
  DialogDismiss as DrawerDismiss,
} from "./dialog";

// The copied BVC side-dialog composition shares the same portal and focus trap.
export function DrawerContent({
  side = "right",
  ...props
}: Omit<ComponentProps<typeof DialogContent>, "side"> & {
  side?: "left" | "right";
}) {
  return <DialogContent side={side} {...props} />;
}
