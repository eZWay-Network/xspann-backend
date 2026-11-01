import { Head, useForm } from "@inertiajs/react";
import { AuthLayout } from "@/components/auth-layout";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormErrors } from "@/components/form-errors";
import { CredentialFields } from "@/components/credential-fields";
export default function Login({
  submit_url,
  destination,
  google_client_id,
}: {
  submit_url: string;
  destination: string;
  google_client_id: string | null;
}) {
  const form = useForm({
    email: "",
    password: "",
    google_token: "",
    destination,
  });
  return (
    <AuthLayout
      title="Welcome back"
      description="Sign in to your XPN Social staff workspace."
    >
      <Head title="Staff sign in · XPN Social" />
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          form.post(submit_url, {
            onFinish: () => form.reset("password", "google_token"),
          });
        }}
      >
        <FormErrors errors={form.errors} />
        <Field label="Email address" error={form.errors.email}>
          <Input
            name="email"
            type="email"
            autoComplete="username"
            value={form.data.email}
            onValueChange={(value) => form.setData("email", value)}
            required
            autoFocus
            disabled={form.processing}
          />
        </Field>
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
        <Button type="submit" className="w-full" loading={form.processing}>
          Continue securely
        </Button>
        <p className="text-center text-xs leading-5 text-muted-foreground">
          You’ll verify an authenticator code next.
          <br />
          Need access or account recovery? Contact your trusted operator.
        </p>
      </form>
    </AuthLayout>
  );
}
