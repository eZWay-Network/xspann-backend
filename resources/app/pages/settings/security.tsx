import { useForm, router, Link } from "@inertiajs/react";
import { Monitor, Smartphone, ShieldCheck } from "lucide-react";
import { useState, useEffect, useRef } from "react";
import { SettingsForm } from "@/components/settings-form";
import { Card } from "@/components/ui/card";
import { SettingsSection } from "@/components/settings-section";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/password-input";
import { Button, buttonVariants } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
  Dialog,
  DialogContent,
  DialogTitle,
  DialogDescription,
  DialogDismiss,
} from "@/components/ui/dialog";
import { FormErrors } from "@/components/form-errors";
import { CredentialFields } from "@/components/credential-fields";
import { EnrollmentSecret } from "@/components/enrollment-secret";
import { useApp } from "@/contexts/app";
type Session = {
  id: number;
  device: string;
  current: boolean;
  authenticated_at: string;
  last_activity_at: string;
  expires_at: string;
  revoke_url: string;
};
type Props = {
  sessions: Session[];
  has_password: boolean;
  password_url: string;
  factor_url: string;
  factor_complete_url: string;
  factor_pending: boolean;
  factor_secret: string | null;
  revoke_all_url: string;
  confirmation_url: string;
  google_client_id: string | null;
};
export default function Security(props: Props) {
  const { preferences, formatDateTime } = useApp();
  const password = useForm({ password: "", new_password: "", mfa_code: "" });
  const passwordErrors: Record<string, string | undefined> = password.errors;
  const factor = useForm({ password: "", google_token: "", mfa_code: "" });
  const complete = useForm({ mfa_code: "" });
  const [passwordOpen, setPasswordOpen] = useState(false);
  const [factorOpen, setFactorOpen] = useState(false);
  const completionCode = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (props.factor_pending) completionCode.current?.focus();
  }, [props.factor_pending]);
  const [revocation, setRevocation] = useState<{
    url: string;
    title: string;
  } | null>(null);
  const [revoking, setRevoking] = useState(false);
  return (
    <div className="space-y-8">
      <SettingsSection
        title="Identity confirmation"
        description="Sensitive changes require fresh credentials and a new authenticator code."
      >
        <Card className="p-5 sm:p-6">
          <p className="mb-4 flex items-center gap-2 text-sm">
            <ShieldCheck className="size-4 text-success" />
            Authenticator protection is enabled.
          </p>
          <Link
            href={props.confirmation_url}
            className={buttonVariants({ variant: "secondary" })}
          >
            Confirm identity
          </Link>
          <p className="mt-3 text-xs leading-5 text-muted-foreground">
            Lost your authenticator? Contact your trusted operator for
            terminal-based recovery. An administrator cannot view your existing
            setup key.
          </p>
        </Card>
      </SettingsSection>
      <SettingsSection
        title="Active sessions"
        description="Only your own active staff browser sessions appear here."
      >
        <div className="max-w-2xl space-y-4">
          <div className="divide-y rounded-lg border bg-card">
            {props.sessions.map((session) => {
              const Icon = session.device.includes("mobile")
                ? Smartphone
                : Monitor;
              return (
                <div
                  key={session.id}
                  className="flex flex-wrap items-center justify-between gap-4 p-4"
                >
                  <div className="flex items-start gap-3">
                    <Icon className="mt-1 size-5 text-muted-foreground" />
                    <div>
                      <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                        {session.device}
                        {session.current && (
                          <Badge tone="success">This session</Badge>
                        )}
                      </p>
                      <p className="mt-1 text-xs text-muted-foreground">
                        Last active{" "}
                        <time
                          dateTime={session.last_activity_at}
                          title={preferences.timezone}
                        >
                          {formatDateTime(session.last_activity_at)}
                        </time>
                      </p>
                    </div>
                  </div>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() =>
                      setRevocation({
                        url: session.revoke_url,
                        title: session.current
                          ? "Sign out of this session?"
                          : `Revoke ${session.device}?`,
                      })
                    }
                  >
                    Revoke
                  </Button>
                </div>
              );
            })}
          </div>
          <Button
            variant="secondary"
            onClick={() =>
              setRevocation({
                url: props.revoke_all_url,
                title: "Sign out of all staff sessions?",
              })
            }
          >
            Sign out of all staff sessions
          </Button>
        </div>
      </SettingsSection>
      {props.has_password && (
        <SettingsSection
          title="Change password"
          description="Updates your XPN Social account password and signs out all customer and staff sessions."
        >
          <Button
            variant="secondary"
            aria-expanded={passwordOpen}
            onClick={() => {
              setPasswordOpen(!passwordOpen);
              password.reset();
              password.clearErrors();
            }}
          >
            {passwordOpen ? "Cancel password change" : "Change password"}
          </Button>
          {passwordOpen && (
            <div className="mt-4">
              <SettingsForm
                note="Changing your account password signs out all customer and staff sessions."
                action={
                  <Button size="sm" type="submit" loading={password.processing}>
                    Change password and sign out
                  </Button>
                }
                onSubmit={(event) => {
                  event.preventDefault();
                  password.post(props.password_url, {
                    preserveScroll: true,
                    onFinish: () => password.reset(),
                  });
                }}
              >
                <FormErrors errors={password.errors} />
                <Field
                  label="Current password"
                  error={passwordErrors.password ?? passwordErrors.credential}
                >
                  <PasswordInput
                    autoComplete="current-password"
                    value={password.data.password}
                    onValueChange={(value) =>
                      password.setData("password", value)
                    }
                    required
                  />
                </Field>
                <Field
                  label="New password"
                  error={password.errors.new_password}
                  description="Use at least 12 characters."
                >
                  <PasswordInput
                    autoComplete="new-password"
                    value={password.data.new_password}
                    onValueChange={(value) =>
                      password.setData("new_password", value)
                    }
                    minLength={12}
                    required
                  />
                </Field>
                <Field
                  label="Fresh authenticator code"
                  error={password.errors.mfa_code}
                >
                  <Input
                    autoComplete="one-time-code"
                    inputMode="numeric"
                    maxLength={6}
                    pattern="[0-9]{6}"
                    value={password.data.mfa_code}
                    onValueChange={(value) =>
                      password.setData("mfa_code", value)
                    }
                    required
                  />
                </Field>
              </SettingsForm>
            </div>
          )}
        </SettingsSection>
      )}
      <SettingsSection
        title="Replace authenticator"
        description="Verify your current factor before enrolling a replacement. Completion signs out every staff session."
      >
        {!props.factor_pending && (
          <Button
            variant="secondary"
            aria-expanded={factorOpen}
            onClick={() => {
              setFactorOpen(!factorOpen);
              factor.reset();
              factor.clearErrors();
            }}
          >
            {factorOpen ? "Cancel replacement" : "Replace authenticator"}
          </Button>
        )}
        {props.factor_pending ? (
          <SettingsForm
            note="Completing replacement signs out every staff session."
            action={
              <Button type="submit" size="sm" loading={complete.processing}>
                Replace authenticator and sign out
              </Button>
            }
            onSubmit={(event) => {
              event.preventDefault();
              complete.post(props.factor_complete_url, {
                onFinish: () => complete.reset(),
              });
            }}
          >
            <FormErrors errors={complete.errors} />
            {props.factor_secret ? (
              <EnrollmentSecret secret={props.factor_secret} />
            ) : (
              <p className="text-description">
                Use the new setup key you just saved. If it was lost, let this
                setup expire in five minutes and verify your current
                authenticator again.
              </p>
            )}
            <Field
              label="New authenticator code"
              error={complete.errors.mfa_code}
            >
              <Input
                ref={completionCode}
                autoComplete="one-time-code"
                inputMode="numeric"
                maxLength={6}
                pattern="[0-9]{6}"
                value={complete.data.mfa_code}
                onValueChange={(value) => complete.setData("mfa_code", value)}
                required
              />
            </Field>
          </SettingsForm>
        ) : (
          factorOpen && (
            <div className="mt-4">
              <SettingsForm
                note="Your current authenticator stays active until you confirm the replacement."
                action={
                  <Button type="submit" size="sm" loading={factor.processing}>
                    Set up replacement
                  </Button>
                }
                onSubmit={(event) => {
                  event.preventDefault();
                  factor.post(props.factor_url, {
                    preserveScroll: true,
                    onFinish: () => factor.reset(),
                  });
                }}
              >
                <FormErrors errors={factor.errors} />
                <CredentialFields
                  replacement
                  errors={factor.errors}
                  clientId={props.google_client_id}
                  password={factor.data.password}
                  googleToken={factor.data.google_token}
                  onPassword={(value) =>
                    factor.setData((data) => ({
                      ...data,
                      password: value,
                      google_token: "",
                    }))
                  }
                  onGoogle={(value) =>
                    factor.setData((data) => ({
                      ...data,
                      google_token: value,
                      password: "",
                    }))
                  }
                />
                <Field
                  label="Current authenticator code"
                  error={factor.errors.mfa_code}
                >
                  <Input
                    autoComplete="one-time-code"
                    inputMode="numeric"
                    maxLength={6}
                    pattern="[0-9]{6}"
                    value={factor.data.mfa_code}
                    onValueChange={(value) => factor.setData("mfa_code", value)}
                    required
                  />
                </Field>
              </SettingsForm>
            </div>
          )
        )}
      </SettingsSection>
      <Dialog
        open={revocation !== null}
        onOpenChange={(open) => {
          if (!open && !revoking) setRevocation(null);
        }}
      >
        <DialogContent>
          <DialogTitle>{revocation?.title}</DialogTitle>
          <DialogDescription className="mt-2">
            Access from the selected browser sessions ends immediately. A new
            sign-in requires credentials and MFA.
          </DialogDescription>
          <DialogDismiss />
          <div className="mt-6 flex justify-end gap-3">
            <Button
              variant="secondary"
              disabled={revoking}
              onClick={() => setRevocation(null)}
            >
              Keep session
            </Button>
            <Button
              variant="danger"
              loading={revoking}
              onClick={() => {
                if (revocation)
                  router.post(
                    revocation.url,
                    {},
                    {
                      onStart: () => setRevoking(true),
                      onFinish: () => {
                        setRevoking(false);
                        setRevocation(null);
                      },
                    },
                  );
              }}
            >
              Revoke access
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}
