import { useState, type ComponentProps } from "react";
import { Eye, EyeOff } from "lucide-react";
import { Input } from "./ui/input";
import { Button } from "./ui/button";
export function PasswordInput(props: ComponentProps<typeof Input>) {
  const [visible, setVisible] = useState(false);
  return (
    <div className="relative">
      <Input
        {...props}
        type={visible ? "text" : "password"}
        className="pr-11"
      />
      <Button
        type="button"
        variant="ghost"
        size="icon"
        className="absolute top-0.5 right-0.5 size-9"
        disabled={props.disabled}
        aria-label={visible ? "Hide password" : "Show password"}
        aria-pressed={visible}
        onClick={() => setVisible(!visible)}
      >
        {visible ? <EyeOff /> : <Eye />}
      </Button>
    </div>
  );
}
