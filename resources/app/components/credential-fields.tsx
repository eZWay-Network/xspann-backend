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
}: {
  password: string;
  googleToken: string;
  clientId: string | null;
  onPassword: (value: string) => void;
  onGoogle: (value: string) => void;
  disabled?: boolean;
}) {
  return (
    <div className="space-y-5">
      <Field label="Password">
        <PasswordInput
          name="password"
          value={password}
          onValueChange={onPassword}
          autoComplete="current-password"
          disabled={disabled}
          required={!googleToken}
        />
      </Field>
      <GoogleCredential clientId={clientId} onCredential={onGoogle} />
      {googleToken && (
        <p role="status" className="text-xs text-success">
          Google credential received. Continue to verify staff access.
        </p>
      )}
    </div>
  );
}
