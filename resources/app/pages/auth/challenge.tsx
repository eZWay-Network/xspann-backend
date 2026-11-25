import { Head, useForm, usePage, router } from "@inertiajs/react";
import { AuthLayout } from "@/components/auth-layout";
import { Field } from "@/components/ui/field";
import { Button } from "@/components/ui/button";
import { FormErrors } from "@/components/form-errors";
import { PasswordInput } from "@/components/password-input";
import type { AppPageProps } from "@/types";
export default function Challenge({ submit_url }: { submit_url: string }) {
  const { urls } = usePage<AppPageProps>().props;
  const form = useForm({ password: "" });
  return (
    <AuthLayout
      title="Verify it’s you"
      description="Confirm your password to continue with this sensitive action."
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

        <Field label="Password" error={form.errors.password}>
          <PasswordInput
            name="password"
            value={form.data.password}
            onValueChange={(value) => form.setData("password", value)}
            autoComplete="current-password"
            disabled={form.processing}
            required
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
