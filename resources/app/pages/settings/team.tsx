import { RecordsPagination } from "@/components/records-pagination";
import { useState } from "react";
import { useForm, usePage, Link, router } from "@inertiajs/react";
import { UserPlus, Copy, UsersRound, MoreHorizontal } from "lucide-react";
import { SettingsSection } from "@/components/settings-section";
import {
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import { Button, buttonVariants } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Select } from "@/components/ui/select";
import { Table, TableHead, TableCell } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Avatar } from "@/components/ui/avatar";
import { Alert } from "@/components/ui/alert";
import {
  Dialog,
  DialogContent,
  DialogTitle,
  DialogDescription,
  DialogDismiss,
} from "@/components/ui/dialog";
import { FormErrors } from "@/components/form-errors";
import { toast } from "@/components/ui/toast";
import { EmptyState } from "@/components/empty-state";
import { useApp } from "@/contexts/app";
type Member = {
  id: number;
  user_id: number;
  name: string;
  username: string;
  role: string;
  enabled: boolean;
  version: number;
  action_url: string;
  show_url: string;
  can_change_role: boolean;
};
type Invitation = {
  id: number;
  name: string;
  username: string;
  role: string;
  expires_at: string;
  cancel_url: string;
};
type PageMeta = { current_page: number; last_page: number; total: number };
type Option = { value: string; label: string };
export default function Team({
  members,
  invitations,
  roles,
  invite_url,
  confirmation_url,
  pagination,
  list_url,
}: {
  members: Member[];
  invitations: Invitation[];
  roles: Option[];
  invite_url: string;
  confirmation_url: string;
  pagination: { members: PageMeta; invitations: PageMeta };
  list_url: string;
}) {
  const { preferences, formatDateTime } = useApp();
  const [busy, setBusy] = useState(false);
  const changePage = (key: "page" | "invitations_page", page: number) =>
    router.get(
      list_url,
      {
        page: pagination.members.current_page,
        invitations_page: pagination.invitations.current_page,
        [key]: page,
      },
      {
        preserveScroll: true,
        preserveState: true,
        onStart: () => setBusy(true),
        onFinish: () => setBusy(false),
      },
    );
  const [inviteOpen, setInviteOpen] = useState(false);
  const [selected, setSelected] = useState<Member | null>(null);
  const [cancel, setCancel] = useState<Invitation | null>(null);
  const { flash } = usePage();
  const invitationUrl =
    typeof flash.invitation_url === "string" ? flash.invitation_url : null;
  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold">Team & access</h2>
          <p className="mt-1 text-description">
            Invite existing accounts and manage fixed staff roles.
          </p>
        </div>
        <Button onClick={() => setInviteOpen(true)}>
          <UserPlus />
          Invite staff member
        </Button>
      </div>
      {invitationUrl && (
        <Alert variant="success">
          <div className="space-y-3">
            <p>
              Copy this one-use invitation link now. It expires in 24 hours and
              is shown only once.
            </p>
            <code className="block break-all text-xs">{invitationUrl}</code>
            <Button
              variant="secondary"
              size="sm"
              onClick={() =>
                void navigator.clipboard.writeText(invitationUrl).then(
                  () => toast.success("Invitation link copied"),
                  () => toast.error("Select and copy the link manually"),
                )
              }
            >
              <Copy />
              Copy invitation link
            </Button>
          </div>
        </Alert>
      )}
      <Card className="overflow-hidden">
        <CardHeader>
          <div>
            <CardTitle>Staff members</CardTitle>
            <CardDescription>
              Role changes and disabling access revoke every staff browser
              session.
            </CardDescription>
          </div>
          <Badge>{pagination.members.total} members</Badge>
        </CardHeader>
        <Table>
          <thead>
            <tr>
              <TableHead>Team member</TableHead>
              <TableHead>Role</TableHead>
              <TableHead>Access</TableHead>
              <TableHead>
                <span className="sr-only">Actions</span>
              </TableHead>
            </tr>
          </thead>
          <tbody>
            {members.map((member) => (
              <tr key={member.id}>
                <TableCell>
                  <div className="flex items-center gap-3">
                    <Avatar name={member.name} />
                    <div>
                      <Link
                        href={member.show_url}
                        className="font-medium hover:text-primary"
                      >
                        {member.name}
                      </Link>
                      <span className="mt-0.5 block text-[11px] text-muted-foreground">
                        @{member.username}
                      </span>
                    </div>
                  </div>
                </TableCell>
                <TableCell className="capitalize">
                  {member.role.replaceAll("_", " ")}
                </TableCell>
                <TableCell>
                  <Badge tone={member.enabled ? "success" : "neutral"}>
                    {member.enabled ? "Enabled" : "Disabled"}
                  </Badge>
                </TableCell>
                <TableCell>
                  <Button
                    size="icon"
                    variant="ghost"
                    aria-label={`Manage ${member.name}`}
                    onClick={() => setSelected(member)}
                  >
                    <MoreHorizontal />
                  </Button>
                </TableCell>
              </tr>
            ))}
          </tbody>
        </Table>
        {!members.length && (
          <EmptyState
            icon={UsersRound}
            title="No staff members"
            description="Use trusted operator enrollment to establish the first administrator."
          />
        )}
      </Card>
      <RecordsPagination
        page={pagination.members.current_page}
        total={pagination.members.total}
        lastPage={pagination.members.last_page}
        busy={busy}
        onChange={(page) => changePage("page", page)}
      />
      {pagination.invitations.total > 0 && (
        <Card className="overflow-hidden">
          <CardHeader>
            <div>
              <CardTitle>Pending invitations</CardTitle>
              <CardDescription>
                Access starts only after the invitee completes enrollment.
              </CardDescription>
            </div>
          </CardHeader>
          <Table>
            <thead>
              <tr>
                <TableHead>Invited account</TableHead>
                <TableHead>Role</TableHead>
                <TableHead>Expires</TableHead>
                <TableHead>Action</TableHead>
              </tr>
            </thead>
            <tbody>
              {invitations.map((invitation) => (
                <tr key={invitation.id}>
                  <TableCell>
                    <span className="font-medium">{invitation.name}</span>
                    <span className="block text-xs text-muted-foreground">
                      @{invitation.username}
                    </span>
                  </TableCell>
                  <TableCell className="capitalize">
                    {invitation.role.replaceAll("_", " ")}
                  </TableCell>
                  <TableCell>
                    <time
                      dateTime={invitation.expires_at}
                      title={preferences.timezone}
                    >
                      {formatDateTime(invitation.expires_at)}
                    </time>
                  </TableCell>
                  <TableCell>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setCancel(invitation)}
                    >
                      Cancel invitation
                    </Button>
                  </TableCell>
                </tr>
              ))}
            </tbody>
          </Table>
          <div className="border-t p-4">
            <RecordsPagination
              page={pagination.invitations.current_page}
              total={pagination.invitations.total}
              lastPage={pagination.invitations.last_page}
              busy={busy}
              onChange={(page) => changePage("invitations_page", page)}
            />
          </div>
        </Card>
      )}
      <SettingsSection
        title="Sensitive changes"
        description="Staff access changes require an identity confirmation within the last five minutes."
      >
        <div>
          <Link
            href={confirmation_url}
            className={buttonVariants({ variant: "secondary" })}
          >
            Confirm identity
          </Link>
          <p className="mt-3 text-xs leading-5 text-muted-foreground">
            The final enabled administrator is protected. A disabled account can
            be enabled with its retained factor, or invited to enroll a new
            factor.
          </p>
        </div>
      </SettingsSection>
      <Dialog open={inviteOpen} onOpenChange={setInviteOpen}>
        <DialogContent>
          <DialogTitle>Invite a staff member</DialogTitle>
          <DialogDescription className="mt-2">
            Choose an existing active, verified account. Your colleague sets up
            their own authenticator.
          </DialogDescription>
          <DialogDismiss />
          {inviteOpen && (
            <InviteForm
              url={invite_url}
              roles={roles}
              confirmationUrl={confirmation_url}
              onSuccess={() => setInviteOpen(false)}
            />
          )}
        </DialogContent>
      </Dialog>
      <Dialog
        open={selected !== null}
        onOpenChange={(open) => {
          if (!open) setSelected(null);
        }}
      >
        <DialogContent>
          <DialogTitle>Manage {selected?.name}</DialogTitle>
          <DialogDescription className="mt-2">
            Changes revoke this member’s staff sessions immediately.
          </DialogDescription>
          <DialogDismiss />
          {selected && (
            <MemberForm
              key={selected.id}
              member={selected}
              roles={roles}
              confirmationUrl={confirmation_url}
              onSuccess={() => setSelected(null)}
            />
          )}
        </DialogContent>
      </Dialog>
      <Dialog
        open={cancel !== null}
        onOpenChange={(open) => {
          if (!open) setCancel(null);
        }}
      >
        <DialogContent>
          <DialogTitle>Cancel invitation</DialogTitle>
          <DialogDescription className="mt-2">
            {cancel?.name} will no longer be able to use this invitation.
          </DialogDescription>
          <DialogDismiss />
          {cancel && (
            <CancelForm invitation={cancel} onSuccess={() => setCancel(null)} />
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
function InviteForm({
  url,
  roles,
  confirmationUrl,
  onSuccess,
}: {
  url: string;
  roles: Option[];
  confirmationUrl: string;
  onSuccess: () => void;
}) {
  const form = useForm({ email: "", role: "moderator", reason: "" });
  return (
    <form
      className="mt-6 space-y-5"
      onSubmit={(event) => {
        event.preventDefault();
        form.post(url, { preserveScroll: true, onSuccess });
      }}
    >
      <FormErrors errors={form.errors} />
      {"confirmation" in form.errors && Boolean(form.errors.confirmation) && (
        <Link
          href={confirmationUrl}
          className={buttonVariants({ variant: "secondary" })}
        >
          Confirm identity
        </Link>
      )}
      <Field label="Existing account email">
        <Input
          type="email"
          value={form.data.email}
          onValueChange={(value) => form.setData("email", value)}
          required
        />
      </Field>
      <Field label="Staff role">
        <Select
          label="Staff role"
          value={form.data.role}
          onValueChange={(value) => form.setData("role", value)}
          options={roles}
          className="w-full"
        />
      </Field>
      <Field label="Reason for access">
        <Textarea
          value={form.data.reason}
          onChange={(event) => form.setData("reason", event.target.value)}
          minLength={5}
          maxLength={500}
          required
        />
      </Field>
      <Button type="submit" loading={form.processing}>
        Create invitation
      </Button>
    </form>
  );
}
function MemberForm({
  member,
  roles,
  confirmationUrl,
  onSuccess,
}: {
  member: Member;
  roles: Option[];
  confirmationUrl: string;
  onSuccess: () => void;
}) {
  const form = useForm({
    action: "revoke_sessions",
    role: member.role,
    version: member.version,
    reason: "",
  });
  return (
    <form
      className="mt-6 space-y-5"
      onSubmit={(event) => {
        event.preventDefault();
        form.patch(member.action_url, { preserveScroll: true, onSuccess });
      }}
    >
      <FormErrors errors={form.errors} />
      {Object.keys(form.errors).length > 0 && (
        <div className="flex gap-2">
          <Link
            href={confirmationUrl}
            className={buttonVariants({ variant: "secondary", size: "sm" })}
          >
            Confirm identity
          </Link>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            onClick={() => {
              onSuccess();
              router.reload();
            }}
          >
            Reload current access
          </Button>
        </div>
      )}
      <Field label="Action">
        <Select
          label="Access action"
          value={form.data.action}
          onValueChange={(value) => form.setData("action", value)}
          options={[
            { value: "revoke_sessions", label: "Revoke all staff sessions" },
            ...(member.can_change_role
              ? [{ value: "role", label: "Change role" }]
              : []),
            ...(member.enabled
              ? [{ value: "disable", label: "Disable staff access" }]
              : [{ value: "enable", label: "Enable retained staff access" }]),
          ]}
          className="w-full"
        />
      </Field>
      {form.data.action === "role" && (
        <Field label="New role">
          <Select
            label="New role"
            value={form.data.role}
            onValueChange={(value) => form.setData("role", value)}
            options={roles}
            className="w-full"
          />
        </Field>
      )}
      <Field label="Reason">
        <Textarea
          value={form.data.reason}
          onChange={(event) => form.setData("reason", event.target.value)}
          minLength={5}
          maxLength={500}
          required
        />
      </Field>
      <Button
        type="submit"
        variant={form.data.action === "disable" ? "danger" : "primary"}
        loading={form.processing}
      >
        Apply access change
      </Button>
    </form>
  );
}
function CancelForm({
  invitation,
  onSuccess,
}: {
  invitation: Invitation;
  onSuccess: () => void;
}) {
  const form = useForm({ reason: "" });
  return (
    <form
      className="mt-6 space-y-5"
      onSubmit={(event) => {
        event.preventDefault();
        form.post(invitation.cancel_url, { onSuccess });
      }}
    >
      <FormErrors errors={form.errors} />
      <Field label="Reason">
        <Textarea
          value={form.data.reason}
          onChange={(event) => form.setData("reason", event.target.value)}
          minLength={5}
          maxLength={500}
          required
        />
      </Field>
      <Button type="submit" variant="danger" loading={form.processing}>
        Cancel invitation
      </Button>
    </form>
  );
}
