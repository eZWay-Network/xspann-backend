import { useForm } from "@inertiajs/react";
import { SettingsSection } from "@/components/settings-section";
import { Input } from "@/components/ui/input";
import { Field } from "@/components/ui/field";
import { Button } from "@/components/ui/button";
import { FormErrors } from "@/components/form-errors";
type Setting = { value: string; version: number };
function SettingForm({
  name,
  setting,
  url,
}: {
  name: string;
  setting: Setting;
  url: string | null;
}) {
  const form = useForm({
    key: name,
    value: setting.value,
    version: setting.version,
  });
  return (
    <form
      className="max-w-xl space-y-4"
      onSubmit={(event) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, version: setting.version }));
        form.patch(url!, { preserveScroll: true });
      }}
    >
      <FormErrors errors={form.errors} />
      <Field
        label={
          name === "workspace_name"
            ? "Workspace name"
            : "Staff support destination"
        }
        description={
          name === "support_url"
            ? "Optional HTTPS link shown in the staff header."
            : "Shown in the overview greeting."
        }
      >
        <Input
          value={form.data.value}
          onValueChange={(value) => form.setData("value", value)}
          readOnly={!url}
          maxLength={255}
          required={name === "workspace_name"}
        />
      </Field>
      {url && (
        <Button type="submit" loading={form.processing}>
          Save setting
        </Button>
      )}
    </form>
  );
}
export default function General({
  settings,
  save_url,
  environment_name,
  business_timezone,
}: {
  settings: Record<string, Setting>;
  save_url: string | null;
  environment_name: string;
  business_timezone: string;
}) {
  return (
    <div className="space-y-8">
      <p className="text-sm text-muted-foreground">
        Environment: {environment_name} · Application timezone:{" "}
        {business_timezone}. Deployment configuration is read-only.
      </p>
      {Object.entries(settings).map(([name, setting]) => (
        <SettingsSection
          key={name}
          title={name === "workspace_name" ? "Workspace identity" : "Support"}
          description="Only non-secret operational settings are editable."
        >
          <SettingForm name={name} setting={setting} url={save_url} />
        </SettingsSection>
      ))}
    </div>
  );
}
