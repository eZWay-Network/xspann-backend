import { router } from "@inertiajs/react";
import { DetailScreen, type DetailProps } from "@/components/staff/detail";
import { Button } from "@/components/ui/button";
import { Alert } from "@/components/ui/alert";
export default function Operation(
  props: DetailProps & {
    status: string;
    refresh_url: string;
    target_url: string;
  },
) {
  return (
    <DetailScreen
      {...props}
      links={[{ label: "Open source record", url: props.target_url }]}
      actions={
        <Button
          variant="secondary"
          onClick={() =>
            router.get(props.refresh_url, {}, { preserveScroll: true })
          }
        >
          Refresh outcome
        </Button>
      }
    >
      {["queued", "running"].includes(props.status) && (
        <Alert variant="info">
          The action is {props.status}. Completion will be shown after the
          worker records its outcome.
        </Alert>
      )}
      {props.status === "failed" && (
        <Alert>
          Recovery could not finish. Review the source record and resolve the
          cause before requesting another attempt.
        </Alert>
      )}
    </DetailScreen>
  );
}
