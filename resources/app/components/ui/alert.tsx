import { CircleAlert, CircleCheck } from "lucide-react";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";
export function Alert({
  children,
  variant = "error",
}: {
  children: ReactNode;
  variant?: "error" | "success";
}) {
  const Icon = variant === "error" ? CircleAlert : CircleCheck;
  return (
    <div
      role={variant === "error" ? "alert" : "status"}
      className={cn(
        "flex items-start gap-2.5 rounded-md border p-3 text-sm leading-5",
        variant === "error"
          ? "border-destructive/15 bg-destructive-soft text-destructive"
          : "border-success/15 bg-success-soft text-success",
      )}
    >
      <Icon className="mt-0.5 size-4 shrink-0" />
      <div>{children}</div>
    </div>
  );
}
