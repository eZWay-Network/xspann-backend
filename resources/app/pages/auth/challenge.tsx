import { Head, useForm, usePage, router } from "@inertiajs/react";
import { AuthLayout } from "@/components/auth-layout";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormErrors } from "@/components/form-errors";
import { CredentialFields } from "@/components/credential-fields";
import type { AppPageProps } from "@/types";
export default function Challenge({
  requires_credential,
  submit_url,
  google_client_id,
}: {
  requires_credential: boolean;
  submit_url: string;
  google_client_id: string | null;
}) {
  const { urls } = usePage<AppPageProps>().props;
  const form = useForm({ password: "", google_token: "", mfa_code: "" });
  return (
    <AuthLayout
      title="Verify it’s you"
      description={
        requires_credential
          ? "Confirm your credentials and a fresh authenticator code to continue."
          : "Enter the six-digit code from your authenticator app."
      }
    >
      <Head title="Verify staff access · XPN Social" />
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          form.post(submit_url, { onFinish: () => form.reset() });
        }}
      >
        <FormErrors errors={form.errors} />
        {requires_credential && (
          <CredentialFields
            password={form.data.password}
            googleToken={form.data.google_token}
            clientId={google_client_id}
            onPassword={(value) =>
              form.setData((data) => ({
                ...data,
                password: value,
                google_token: "",
              }))
            }
            onGoogle={(value) =>
              form.setData((data) => ({
                ...data,
                google_token: value,
                password: "",
              }))
            }
            disabled={form.processing}
          />
        )}
        <Field
          label="Authenticator code"
          error={form.errors.mfa_code}
          description="Each code can be used once. Wait for the next code if you just used this one."
        >
          <Input
            name="mfa_code"
            inputMode="numeric"
            pattern="[0-9]{6}"
            maxLength={6}
            autoComplete="one-time-code"
            value={form.data.mfa_code}
            onValueChange={(value) => form.setData("mfa_code", value)}
            className="text-center text-xl tracking-[0.35em]"
            required
            autoFocus
          />
        </Field>
        <Button type="submit" className="w-full" loading={form.processing}>
          Verify and continue
        </Button>
        <Button
          type="button"
          variant="ghost"
          className="w-full"
          onClick={() => router.post(urls.logout)}
        >
          Cancel and sign out
        </Button>
      </form>
    </AuthLayout>
  );
}
