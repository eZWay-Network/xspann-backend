import { useForm } from "@inertiajs/react";
import { AuthLayout } from "@/components/auth-layout";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormErrors } from "@/components/form-errors";
export default function ResetPassword({
  email,
  token,
  submit_url,
}: {
  email: string;
  token: string;
  submit_url: string;
}) {
  const form = useForm({
    email,
    token,
    password: "",
    password_confirmation: "",
  });
  return (
    <AuthLayout
      title="Choose a new password"
      description="The link works once. Existing sessions will be revoked."
    >
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          form.post(submit_url, {
            onFinish: () => form.reset("password", "password_confirmation"),
          });
        }}
      >
        <FormErrors errors={form.errors} />
        <input type="hidden" name="email" value={form.data.email} />
        <Field label="New password">
          <Input
            type="password"
            required
            minLength={8}
            autoComplete="new-password"
            value={form.data.password}
            onValueChange={(value) => form.setData("password", value)}
          />
        </Field>
        <Field label="Confirm new password">
          <Input
            type="password"
            required
            minLength={8}
            autoComplete="new-password"
            value={form.data.password_confirmation}
            onValueChange={(value) =>
              form.setData("password_confirmation", value)
            }
          />
        </Field>
        <Button type="submit" className="w-full" loading={form.processing}>
          Reset password
        </Button>
      </form>
    </AuthLayout>
  );
}
