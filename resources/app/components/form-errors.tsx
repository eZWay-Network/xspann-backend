import { Alert } from "@/components/ui/alert";
export function FormErrors({ errors }: { errors: Record<string, unknown> }) {
  const messages = Object.values(errors).flatMap((value) =>
    typeof value === "string" ? [value] : [],
  );
  return messages.length ? (
    <Alert>
      <ul className="space-y-1">
        {[...new Set(messages)].map((message) => (
          <li key={message}>{message}</li>
        ))}
      </ul>
    </Alert>
  ) : null;
}
