import { router } from "@inertiajs/react";
import { DetailScreen, type DetailProps } from "@/components/staff/detail";
import { Button, buttonVariants } from "@/components/ui/button";
import type { ReportRange } from "@/components/staff/report-filters";
export default function Export(
  props: DetailProps & {
    filters: ReportRange;
    download_url: string | null;
    refresh_url: string;
  },
) {
  return (
    <DetailScreen {...props}>
      <p className="text-sm text-muted-foreground">
        Requested range: {props.filters.from} to {props.filters.to} · UTC.
        Snapshot metrics use generation time.
      </p>
      <div className="flex gap-3">
        {props.download_url && (
          <a className={buttonVariants()} href={props.download_url}>
            Download CSV
          </a>
        )}
        <Button
          variant="secondary"
          onClick={() =>
            router.get(props.refresh_url, {}, { preserveScroll: true })
          }
        >
          Refresh export
        </Button>
      </div>
    </DetailScreen>
  );
}
