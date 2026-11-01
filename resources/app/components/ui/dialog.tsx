import { Dialog as Primitive } from "@base-ui/react/dialog";
import type { ComponentProps } from "react";
import { X } from "lucide-react";
import { cn } from "@/lib/utils";
import { buttonVariants } from "./button";
export const Dialog = Primitive.Root;
export const DialogTrigger = Primitive.Trigger;
export const DialogClose = Primitive.Close;
export function DialogTitle({
  className,
  ...props
}: Omit<ComponentProps<typeof Primitive.Title>, "className"> & {
  className?: string;
}) {
  return (
    <Primitive.Title
      className={cn("text-base font-semibold tracking-tight", className)}
      {...props}
    />
  );
}
export function DialogDescription({
  className,
  ...props
}: Omit<ComponentProps<typeof Primitive.Description>, "className"> & {
  className?: string;
}) {
  return (
    <Primitive.Description
      className={cn("text-description", className)}
      {...props}
    />
  );
}
export function DialogContent({
  className,
  children,
  side = false,
  ...props
}: Omit<ComponentProps<typeof Primitive.Popup>, "className"> & {
  className?: string;
  side?: boolean | "left" | "right";
}) {
  return (
    <Primitive.Portal>
      <Primitive.Backdrop className="fixed inset-0 z-50 bg-foreground/25 backdrop-blur-xs transition-opacity data-ending-style:opacity-0 data-starting-style:opacity-0" />
      <Primitive.Popup
        className={cn(
          "fixed z-50 bg-card shadow-popover outline-none",
          side
            ? cn(
                "inset-y-0 flex h-dvh max-w-full flex-col",
                side === "right"
                  ? "right-0 w-110 border-l"
                  : "left-0 w-72 border-r",
              )
            : "top-[15vh] left-1/2 w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 overflow-hidden rounded-xl border overflow-y-auto max-h-[75vh] p-6",
          className,
        )}
        {...props}
      >
        {children}
      </Primitive.Popup>
    </Primitive.Portal>
  );
}
export function DialogDismiss() {
  return (
    <Primitive.Close
      aria-label="Close dialog"
      className={cn(
        buttonVariants({ variant: "ghost", size: "icon" }),
        "absolute top-3 right-3",
      )}
    >
      <X className="size-4" />
    </Primitive.Close>
  );
}
