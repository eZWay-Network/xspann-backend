import type { ReactNode } from "react";
import { Toast } from "@base-ui/react/toast";
import { CircleCheck, CircleAlert, X } from "lucide-react";
import { buttonVariants } from "@/components/ui/button";

const manager = Toast.createToastManager();

export const toast = {
  success: (title: string, description?: string) =>
    manager.add({ title, description, type: "success" }),
  error: (title: string, description?: string) =>
    manager.add({ title, description, type: "error" }),
};

export function ToastProvider({ children }: { children: ReactNode }) {
  return (
    <Toast.Provider toastManager={manager} timeout={5000} limit={3}>
      {children}
      <ToastList />
    </Toast.Provider>
  );
}

function ToastList() {
  const { toasts } = Toast.useToastManager();

  return (
    <Toast.Portal>
      <Toast.Viewport
        className="pointer-events-none fixed right-4 bottom-4 left-4 z-70 flex flex-col gap-3 outline-none sm:right-6 sm:bottom-6 sm:left-auto sm:w-90"
        aria-label="Notifications"
      >
        {toasts.map((item) => (
          <Toast.Root
            key={item.id}
            toast={item}
            className="pointer-events-auto relative flex items-start gap-3 rounded-xl border border-border bg-card p-4 shadow-popover outline-none transition-[opacity,translate] duration-200 data-limited:hidden data-starting-style:translate-y-2 data-starting-style:opacity-0 data-ending-style:translate-x-6 data-ending-style:opacity-0"
            style={{
              transform:
                "translate(var(--toast-swipe-movement-x, 0px), var(--toast-swipe-movement-y, 0px))",
            }}
          >
            <span
              className={`flex size-8 shrink-0 items-center justify-center rounded-full ${item.type === "error" ? "bg-destructive/10 text-destructive" : "bg-success-soft text-success"}`}
            >
              {item.type === "error" ? (
                <CircleAlert className="size-4.5" aria-hidden="true" />
              ) : (
                <CircleCheck className="size-4.5" aria-hidden="true" />
              )}
            </span>
            <Toast.Content className="min-w-0 flex-1 pt-1">
              <Toast.Title className="text-sm font-medium leading-5" />
              <Toast.Description className="mt-1 text-xs leading-5 text-muted-foreground" />
            </Toast.Content>
            <Toast.Close
              aria-label="Dismiss notification"
              className={buttonVariants({
                variant: "ghost",
                size: "icon",
                className: "-mt-1 -mr-1 size-8",
              })}
            >
              <X aria-hidden="true" />
            </Toast.Close>
          </Toast.Root>
        ))}
      </Toast.Viewport>
    </Toast.Portal>
  );
}
