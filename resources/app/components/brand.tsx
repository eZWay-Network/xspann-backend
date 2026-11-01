import { AudioLines } from "lucide-react";
import { cn } from "@/lib/utils";
export function BrandIcon({ className }: { className?: string }) {
  return (
    <span
      className={cn(
        "flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary text-primary-foreground",
        className,
      )}
    >
      <AudioLines className="size-6" strokeWidth={1.7} />
    </span>
  );
}
export default function Brand({ compact = false }: { compact?: boolean }) {
  return (
    <div
      className="flex shrink-0 items-center gap-2.5 whitespace-nowrap"
      role="img"
      aria-label="XPN Social"
    >
      <BrandIcon />
      {!compact && (
        <>
          <span className="border-r pr-3 text-2xl leading-10 font-bold tracking-[-0.055em] text-primary">
            xpn<span className="text-chart-2">.</span>
          </span>
          <span className="text-[0.65rem] font-medium leading-[1.45] tracking-[-0.015em]">
            XPN Social
            <span className="block text-muted-foreground">Staff workspace</span>
          </span>
        </>
      )}
    </div>
  );
}
