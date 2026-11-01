import {
  PreferencesForm,
  type Preferences,
} from "@/components/staff/preferences-form";
import { useForm } from "@inertiajs/react";
import { SettingsSection } from "@/components/settings-section";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { FormErrors } from "@/components/form-errors";
export default function Profile({
  profile,
  preferences,
  timezones,
  profile_url,
  preferences_url,
}: {
  profile: { name: string; username: string };
  preferences: Preferences;
  timezones: string[];
  profile_url: string;
  preferences_url: string;
}) {
  const name = useForm({ name: profile.name });
  return (
    <div className="space-y-8">
      <SettingsSection
        title="Your profile"
        description="Your display name is shared with your XPN Social account."
      >
        <form
          className="max-w-xl space-y-5"
          onSubmit={(event) => {
            event.preventDefault();
            name.patch(profile_url, { preserveScroll: true });
          }}
        >
          <FormErrors errors={name.errors} />
          <Field label="Display name" error={name.errors.name}>
            <Input
              value={name.data.name}
              onValueChange={(value) => name.setData("name", value)}
              required
              maxLength={100}
              autoComplete="name"
            />
          </Field>
          <Field
            label="Username"
            description="Account identity changes are managed through your customer account."
          >
            <Input value={profile.username} readOnly />
          </Field>
          <Button type="submit" loading={name.processing}>
            Save profile
          </Button>
        </form>
      </SettingsSection>
      <PreferencesForm
        preferences={preferences}
        timezones={timezones}
        preferences_url={preferences_url}
      />
    </div>
  );
}
