import { useEffect, useRef, useState } from "react";
import { Alert } from "@/components/ui/alert";
type GoogleIdentity = {
  accounts: {
    id: {
      initialize: (options: {
        client_id: string;
        callback: (response: { credential: string }) => void;
        auto_select: boolean;
      }) => void;
      renderButton: (
        parent: HTMLElement,
        options: { theme: string; size: string; text: string },
      ) => void;
    };
  };
};
declare global {
  interface Window {
    google?: GoogleIdentity;
  }
}
export function GoogleCredential({
  clientId,
  onCredential,
  alignStart = false,
}: {
  clientId: string | null;
  alignStart?: boolean;
  onCredential: (credential: string) => void;
}) {
  const element = useRef<HTMLDivElement>(null);
  const callback = useRef(onCredential);
  callback.current = onCredential;
  const [loading, setLoading] = useState(Boolean(clientId));
  const [unavailable, setUnavailable] = useState(false);
  useEffect(() => {
    if (!clientId) return;
    let cancelled = false;
    let script: HTMLScriptElement | null = null;
    const fail = () => {
      if (!cancelled) {
        setLoading(false);
        setUnavailable(true);
      }
    };
    const timeout = window.setTimeout(fail, 8000);
    const mount = () => {
      if (cancelled || !element.current || !window.google) return;
      try {
        window.google.accounts.id.initialize({
          client_id: clientId,
          auto_select: false,
          callback: (response) => callback.current(response.credential),
        });
        window.google.accounts.id.renderButton(element.current, {
          theme: "outline",
          size: "large",
          text: "signin_with",
        });
        window.clearTimeout(timeout);
        setLoading(false);
        setUnavailable(false);
      } catch {
        fail();
      }
    };
    if (window.google) mount();
    else {
      script = document.querySelector<HTMLScriptElement>(
        "script[data-staff-google]",
      );
      if (!script) {
        script = document.createElement("script");
        script.src = "https://accounts.google.com/gsi/client";
        script.async = true;
        script.dataset.staffGoogle = "true";
        document.head.append(script);
      }
      script.addEventListener("load", mount, { once: true });
      script.addEventListener("error", fail, { once: true });
    }
    return () => {
      cancelled = true;
      window.clearTimeout(timeout);
      script?.removeEventListener("load", mount);
      script?.removeEventListener("error", fail);
    };
  }, [clientId]);
  if (!clientId) return null;
  return (
    <div
      className={alignStart ? "space-y-3 text-left" : "space-y-3 text-center"}
    >
      <p className="text-xs text-muted-foreground">
        Or use your linked Google account
      </p>
      {loading && (
        <p role="status" className="text-xs text-muted-foreground">
          Loading Google sign-in…
        </p>
      )}
      <div
        ref={element}
        className={alignStart ? "flex justify-start" : "flex justify-center"}
      />
      {unavailable && (
        <Alert>
          Google sign-in could not load. Try again or use your password.
        </Alert>
      )}
    </div>
  );
}
