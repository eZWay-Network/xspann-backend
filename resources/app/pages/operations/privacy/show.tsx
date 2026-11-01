import { useForm } from "@inertiajs/react";
import { DetailScreen, type DetailProps } from "@/components/staff/detail";
import { FormPanel } from "@/components/staff/form-panel";
import { ReasonField } from "@/components/staff/review-forms";
export default function Privacy(
  props: DetailProps & { retry_url: string | null; idempotency_key: string },
) {
  const form = useForm({ reason: "", idempotency_key: props.idempotency_key });
  return (
    <DetailScreen {...props}>
      {props.retry_url && (
        <FormPanel
          title="Retry failed request"
          description="Confirm the underlying issue has been addressed. The worker rechecks permission and records the outcome."
          busy={form.processing}
          errors={form.errors}
          submit="Queue retry"
          onSubmit={(event) => {
            event.preventDefault();
            form.post(props.retry_url!);
          }}
        >
          <ReasonField
            value={form.data.reason}
            onChange={(value) => form.setData("reason", value)}
          />
        </FormPanel>
      )}
    </DetailScreen>
  );
}
