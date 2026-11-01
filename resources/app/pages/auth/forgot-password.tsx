import { Link, useForm } from "@inertiajs/react";
import { AuthLayout } from "@/components/auth-layout";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormErrors } from "@/components/form-errors";
export default function ForgotPassword({ submit_url }: { submit_url: string }) {
  const form = useForm({ email: "" });
  return (
    <AuthLayout
      title="Reset your password"
      description="Use your verified account email. Your staff role and authenticator remain unchanged."
    >
      <form
        className="space-y-5"
        onSubmit={(event) => {
          event.preventDefault();
          form.post(submit_url);
        }}
      >
        <FormErrors errors={form.errors} />
        <Field label="Email address">
          <Input
            type="email"
            autoComplete="email"
            required
            value={form.data.email}
            onValueChange={(value) => form.setData("email", value)}
          />
        </Field>
        <Button type="submit" className="w-full" loading={form.processing}>
          Send reset link
        </Button>
        <Link className="block text-center text-sm text-primary" href="/login">
          Back to sign in
        </Link>
      </form>
    </AuthLayout>
  );
}
