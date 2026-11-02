import { Head, Link, useForm } from "@inertiajs/react";
import { AuthLayout } from "@/components/auth-layout";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormErrors } from "@/components/form-errors";
import { PasswordInput } from "@/components/password-input";
export default function Login({
  submit_url,
  destination,
}: {
  submit_url: string;
  destination: string;
}) {
  const form = useForm({
    email: "",
    password: "",
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
          form.post(submit_url, { onFinish: () => form.reset("password") });
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
        <Field label="Password" error={form.errors.password}>
          <PasswordInput
            name="password"
            autoComplete="current-password"
            value={form.data.password}
            onValueChange={(value) => form.setData("password", value)}
            disabled={form.processing}
            required
          />
        </Field>
        <Button type="submit" className="w-full" loading={form.processing}>
          Continue securely
        </Button>
        <Link
          href="/forgot-password"
          className="block text-center text-sm text-primary"
        >
          Forgot password?
        </Link>
        <p className="text-center text-xs leading-5 text-muted-foreground">
          You’ll verify an authenticator code next.
        </p>
      </form>
    </AuthLayout>
  );
}
