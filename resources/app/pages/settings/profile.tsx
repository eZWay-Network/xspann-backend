import { useForm } from "@inertiajs/react";
import { SettingsSection } from "@/components/settings-section";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Checkbox } from "@/components/ui/checkbox";
import { Button } from "@/components/ui/button";
import { FormErrors } from "@/components/form-errors";
type Preferences = {
  timezone: string;
  locale: string;
  page_size: number;
  attention_enabled: boolean;
  version: number;
};
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
  const settings = useForm(preferences);
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
      <SettingsSection
        title="Workspace preferences"
        description="Choose how dates and account reminders appear in your staff workspace. Reporting boundaries remain UTC."
      >
        <form
          className="max-w-xl space-y-5"
          onSubmit={(event) => {
            event.preventDefault();
            settings.patch(preferences_url, {
              preserveScroll: true,
              onSuccess: (page) =>
                settings.setData(
                  (page.props as unknown as { preferences: Preferences })
                    .preferences,
                ),
            });
          }}
        >
          <FormErrors errors={settings.errors} />
          <div className="grid gap-5 sm:grid-cols-2">
            <Field label="Display timezone">
              <Select
                label="Display timezone"
                value={settings.data.timezone}
                onValueChange={(value) => settings.setData("timezone", value)}
                options={timezones.map((value) => ({
                  value,
                  label: value.replaceAll("_", " "),
                }))}
                className="w-full max-w-full"
              />
            </Field>
            <Field label="Language">
              <Select
                label="Language"
                value={settings.data.locale}
                options={[{ value: "en", label: "English" }]}
                onValueChange={(value) => settings.setData("locale", value)}
              />
            </Field>
          </div>
          <Field
            label="Rows per page"
            description="Default size for staff record lists."
          >
            <Select
              label="Rows per page"
              value={String(settings.data.page_size)}
              onValueChange={(value) =>
                settings.setData("page_size", Number(value))
              }
              options={[10, 20, 50].map((value) => ({
                value: String(value),
                label: `${value} rows`,
              }))}
            />
          </Field>
          <label className="flex items-start gap-3 text-sm">
            <Checkbox
              checked={settings.data.attention_enabled}
              onCheckedChange={(value) =>
                settings.setData("attention_enabled", Boolean(value))
              }
            />
            <span>
              Show attention badges
              <span className="mt-1 block text-xs text-muted-foreground">
                The attention page remains available at any time.
              </span>
            </span>
          </label>
          <Button type="submit" loading={settings.processing}>
            Save preferences
          </Button>
        </form>
      </SettingsSection>
    </div>
  );
}
