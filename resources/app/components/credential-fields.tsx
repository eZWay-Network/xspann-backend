import { Field } from "@/components/ui/field";
import { PasswordInput } from "@/components/password-input";
import { GoogleCredential } from "./google-credential";
export function CredentialFields({
  password,
  googleToken,
  clientId,
  onPassword,
  onGoogle,
  disabled = false,
  replacement = false,
  errors = {},
}: {
  password: string;
  googleToken: string;
  clientId: string | null;
  onPassword: (value: string) => void;
  onGoogle: (value: string) => void;
  disabled?: boolean;
  replacement?: boolean;
  errors?: { password?: string; google_token?: string; credential?: string };
}) {
  return (
    <div className="space-y-5">
      {replacement && (
        <p className="text-sm font-medium">
          {clientId
            ? "Confirm your identity with your password or linked Google account."
            : "Confirm your identity with your password."}
        </p>
      )}
      <Field
        label="Password"
        error={
          errors.password ?? (!googleToken ? errors.credential : undefined)
        }
      >
        <PasswordInput
          name="password"
          value={password}
          onValueChange={onPassword}
          autoComplete="current-password"
          disabled={disabled}
          required={!googleToken}
        />
      </Field>
      <GoogleCredential
        clientId={clientId}
        onCredential={onGoogle}
        alignStart={replacement}
      />
      {(errors.google_token || (googleToken && errors.credential)) && (
        <p className="text-xs text-destructive">
          {errors.google_token ?? errors.credential}
        </p>
      )}
      {googleToken && (
        <p role="status" className="text-xs text-success">
          {replacement
            ? "Google credential received. Enter your current authenticator code."
            : "Google credential received. Continue to verify staff access."}
        </p>
      )}
    </div>
  );
}
