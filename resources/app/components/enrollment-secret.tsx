import { Copy, KeyRound } from "lucide-react";
import { Button } from "@/components/ui/button";
import { toast } from "@/components/ui/toast";
export function EnrollmentSecret({ secret }: { secret: string }) {
  return (
    <div className="space-y-3 rounded-lg border bg-background p-4">
      <p className="flex items-center gap-2 text-sm font-medium">
        <KeyRound className="size-4 text-primary" />
        Set up your authenticator
      </p>
      <p className="text-xs leading-5 text-muted-foreground">
        Add a time-based account named “XPN Social” in your authenticator app.
        Enter this setup key; use 6 digits and a 30-second interval. It is
        displayed only once.
      </p>
      <code className="block break-all rounded-md border bg-card p-3 text-sm tracking-wider">
        {secret}
      </code>
      <Button
        type="button"
        variant="secondary"
        size="sm"
        onClick={() =>
          void navigator.clipboard.writeText(secret).then(
            () => toast.success("Setup key copied"),
            () => toast.error("Select and copy the setup key manually"),
          )
        }
      >
        <Copy />
        Copy setup key
      </Button>
    </div>
  );
}
