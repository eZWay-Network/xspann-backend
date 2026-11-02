import { Head, useForm, router } from "@inertiajs/react";
import { AuthLayout } from "@/components/auth-layout";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormErrors } from "@/components/form-errors";
import { PasswordInput } from "@/components/password-input";
import { EnrollmentSecret } from "@/components/enrollment-secret";
export default function Enroll({
  stage,
  secret,
  credential_url,
  complete_url,
  restart_url,
}: {
  stage: "credential" | "authenticator";
  secret: string | null;
  credential_url: string;
  complete_url: string;
  restart_url: string;
}) {
  const credential = useForm({ email: "", password: "" });
  const factor = useForm({ mfa_code: "" });
  return (
    <AuthLayout
      title="Join the staff workspace"
      description="Verify your existing XPN Social account, then enroll your own authenticator."
    >
      <Head title="Staff enrollment · XPN Social" />
      {stage === "credential" ? (
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
            Verify account
          </Button>
        </form>
      ) : (
        <form
          className="space-y-5"
          onSubmit={(event) => {
            event.preventDefault();
            factor.post(complete_url, { onFinish: () => factor.reset() });
          }}
        >
          <FormErrors errors={factor.errors} />
          {secret ? (
            <EnrollmentSecret secret={secret} />
          ) : (
            <p className="text-description">
              Use the key already saved in your authenticator. If you haven’t
              saved it, restart setup below.
            </p>
          )}
          <Field label="New authenticator code">
            <Input
              inputMode="numeric"
              pattern="[0-9]{6}"
              maxLength={6}
              autoComplete="one-time-code"
              value={factor.data.mfa_code}
              onValueChange={(value) => factor.setData("mfa_code", value)}
              required
            />
          </Field>
          <Button type="submit" loading={factor.processing} className="w-full">
            Activate staff access
          </Button>
          <Button
            type="button"
            variant="ghost"
            className="w-full"
            onClick={() => router.post(restart_url)}
          >
            Restart authenticator setup
          </Button>
        </form>
      )}
    </AuthLayout>
  );
}
