import { useForm } from "@inertiajs/react";
import { SettingsSection } from "@/components/settings-section";
import { SettingsForm } from "@/components/settings-form";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormErrors } from "@/components/form-errors";

export default function Profile({
  profile,
  profile_url,
}: {
  profile: { name: string; username: string };
  profile_url: string;
}) {
  const form = useForm({ name: profile.name });
  return (
    <SettingsSection
      title="Your profile"
      description="Manage the identity shown on your staff account."
    >
      <SettingsForm
        onSubmit={(event) => {
          event.preventDefault();
          form.patch(profile_url, {
            preserveScroll: true,
            onSuccess: (page) =>
              form.setData("name", (page.props.profile as typeof profile).name),
          });
        }}
        note="Your display name is shared with your XPN Social account."
        action={
          <Button
            type="submit"
            size="sm"
            loading={form.processing}
            disabled={
              !form.data.name.trim() || form.data.name.trim() === profile.name
            }
          >
            Save profile
          </Button>
        }
      >
        <FormErrors errors={form.errors} />
        <Field label="Display name" error={form.errors.name}>
          <Input
            value={form.data.name}
            onValueChange={(value) => form.setData("name", value)}
            required
            maxLength={100}
            autoComplete="name"
          />
        </Field>
        <Field
          label="Username"
          description="Account identity changes are managed through your customer account."
        >
          <Input
            value={profile.username}
            readOnly
            className="bg-muted text-muted-foreground"
          />
        </Field>
      </SettingsForm>
    </SettingsSection>
  );
}
