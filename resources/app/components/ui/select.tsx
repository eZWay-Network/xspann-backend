import { Select as Primitive } from "@base-ui/react/select";
import { Check, ChevronDown } from "lucide-react";
import { cn } from "@/lib/utils";
export function Select({
  value,
  defaultValue,
  name,
  disabled,
  required,
  onValueChange,
  options,
  label,
  className,
}: {
  value?: string;
  defaultValue?: string;
  name?: string;
  disabled?: boolean;
  required?: boolean;
  onValueChange?: (value: string) => void;
  options: { value: string; label: string }[];
  label: string;
  className?: string;
}) {
  return (
    <Primitive.Root
      value={value}
      defaultValue={defaultValue}
      name={name}
      disabled={disabled}
      required={required}
      onValueChange={(next) => {
        if (next !== null) onValueChange?.(next);
      }}
      items={options}
    >
      <Primitive.Trigger
        aria-label={label}
        className={cn(
          "focus-ring flex h-10 shrink-0 items-center justify-between gap-3 rounded-md border border-input bg-card px-3 text-xs font-medium whitespace-nowrap shadow-card hover:border-muted-foreground/40 disabled:cursor-not-allowed disabled:opacity-50 data-invalid:border-destructive",
          className,
        )}
      >
        <Primitive.Value />
        <Primitive.Icon>
          <ChevronDown className="size-3.5 text-muted-foreground" />
        </Primitive.Icon>
      </Primitive.Trigger>
      <Primitive.Portal>
        <Primitive.Positioner align="end" sideOffset={6} className="z-50">
          <Primitive.Popup className="max-h-72 max-w-[calc(100vw-2rem)] overflow-y-auto min-w-40 rounded-lg border bg-card p-1 shadow-popover outline-none">
            {options.map((option) => (
              <Primitive.Item
                key={option.value}
                value={option.value}
                className="menu-item"
              >
                <Primitive.ItemText className="flex-1">
                  {option.label}
                </Primitive.ItemText>
                <Primitive.ItemIndicator>
                  <Check className="size-4" />
                </Primitive.ItemIndicator>
              </Primitive.Item>
            ))}
          </Primitive.Popup>
        </Primitive.Positioner>
      </Primitive.Portal>
    </Primitive.Root>
  );
}
