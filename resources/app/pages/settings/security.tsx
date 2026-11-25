import { useForm, router, Link } from "@inertiajs/react";
import { Monitor, Smartphone, ShieldCheck } from "lucide-react";
import { useState } from "react";
import { SettingsForm } from "@/components/settings-form";
import { Card } from "@/components/ui/card";
import { SettingsSection } from "@/components/settings-section";
import { Field } from "@/components/ui/field";
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
  revoke_all_url: string;
  confirmation_url: string;
};
export default function Security(props: Props) {
  const { preferences, formatDateTime } = useApp();
  const password = useForm({ password: "", new_password: "" });
  const [passwordOpen, setPasswordOpen] = useState(false);
  const [revocation, setRevocation] = useState<{
    url: string;
    title: string;
  } | null>(null);
  const [revoking, setRevoking] = useState(false);
  return (
    <div className="space-y-8">
      <SettingsSection
        title="Identity confirmation"
        description="Sensitive changes require your password."
      >
        <Card className="p-5 sm:p-6">
          <p className="mb-4 flex items-center gap-2 text-sm">
            <ShieldCheck className="size-4 text-success" />
            Password confirmation protects sensitive changes.
          </p>
          <Link
            href={props.confirmation_url}
            className={buttonVariants({ variant: "secondary" })}
          >
            Confirm identity
          </Link>
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
                  error={password.errors.password}
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
              </SettingsForm>
            </div>
          )}
        </SettingsSection>
      )}
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
            sign-in requires your password.
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
