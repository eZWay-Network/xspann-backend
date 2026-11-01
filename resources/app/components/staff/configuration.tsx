import { DetailFields, type DetailField } from "@/components/staff/detail";
import { Alert } from "@/components/ui/alert";
export function Configuration({
  sections,
}: {
  sections: { title: string; fields: DetailField[] }[];
}) {
  return (
    <div className="space-y-6">
      <Alert variant="info">
        Read-only deployment configuration. Changes require the normal reviewed
        deployment process.
      </Alert>
      {sections.map((section) => (
        <DetailFields
          key={section.title}
          title={section.title}
          fields={section.fields}
        />
      ))}
    </div>
  );
}
