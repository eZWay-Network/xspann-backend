import { Head, useForm } from "@inertiajs/react";
import { AuthLayout } from "@/components/auth-layout";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormErrors } from "@/components/form-errors";
import { PasswordInput } from "@/components/password-input";
export default function Enroll({ credential_url }: { credential_url: string }) {
  const credential = useForm({ email: "", password: "" });
  return (
    <AuthLayout
      title="Join the staff workspace"
      description="Verify your existing XPN Social account to accept this invitation."
    >
      <Head title="Staff enrollment · XPN Social" />

      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          credential.post(credential_url, {
            onFinish: () => credential.reset("password"),
          });
        }}
      >
        <FormErrors errors={credential.errors} />
        <Field label="Invited email address">
          <Input
            type="email"
            autoComplete="username"
            value={credential.data.email}
            onValueChange={(value) => credential.setData("email", value)}
            required
          />
        </Field>
        <Field label="Password" error={credential.errors.password}>
          <PasswordInput
            name="password"
            value={credential.data.password}
            onValueChange={(value) => credential.setData("password", value)}
            autoComplete="current-password"
            disabled={credential.processing}
            required
          />
        </Field>
        <Button
          type="submit"
          className="w-full"
          loading={credential.processing}
        >
          Accept invitation
        </Button>
      </form>
    </AuthLayout>
  );
}
