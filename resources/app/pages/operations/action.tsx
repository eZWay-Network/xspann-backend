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
      links={[{ label: "Open privacy request", url: props.target_url }]}
    >
      {["queued", "running"].includes(props.status) && (
        <Alert>
          The action is {props.status}. Completion will be shown after the
          worker records its outcome.
        </Alert>
      )}
      <Button
        variant="secondary"
        onClick={() =>
          router.get(props.refresh_url, {}, { preserveScroll: true })
        }
      >
        Refresh outcome
      </Button>
    </DetailScreen>
  );
}
