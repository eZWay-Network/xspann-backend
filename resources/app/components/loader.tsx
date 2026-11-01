import { LoaderCircle } from "lucide-react";
import Brand from "./brand";
import { Skeleton } from "./ui/skeleton";
export default function PageLoader({
  fullPage = true,
}: {
  fullPage?: boolean;
}) {
  if (!fullPage)
    return (
      <div className="page-stack" role="status" aria-label="Loading page">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-4 w-72 max-w-full" />
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {[0, 1, 2, 3].map((value) => (
            <Skeleton key={value} className="h-32" />
          ))}
        </div>
        <Skeleton className="h-80" />
        <span className="sr-only">Loading page…</span>
      </div>
    );
  return (
    <div
      className="flex min-h-dvh flex-col items-center justify-center gap-8 bg-card"
      role="status"
    >
      <Brand />
      <div className="flex items-center gap-2 text-xs text-muted-foreground">
        <LoaderCircle className="size-8 animate-spin text-primary" />
      </div>
    </div>
  );
}
