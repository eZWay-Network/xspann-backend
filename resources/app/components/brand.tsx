import { cn } from "@/lib/utils";

export default function Brand({ className }: { className?: string }) {
  return (
    <img
      src="/assets/xpn-logo.webp"
      alt="XPN Social"
      width={256}
      height={94}
      className={cn("h-10 w-auto shrink-0 object-contain", className)}
    />
  );
}
