import { useState } from "react";
import { DatePicker } from "@/components/ui/date-picker";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
/** UTC scheduling avoids ambiguous browser-local and daylight-saving interpretations. */
export function DateTime({
  label,
  value,
  onChange,
  required = true,
}: {
  label: string;
  value: string;
  onChange: (value: string) => void;
  required?: boolean;
}) {
  const [time, setTime] = useState(value.slice(11, 16) || "12:00");
  const date = value.slice(0, 10);
  return (
    <div className="grid gap-2 sm:grid-cols-[1fr_7rem_6rem]">
      <DatePicker
        label={label}
        value={date}
        required={required}
        onChange={(day) => onChange(day ? `${day}T${time}:00Z` : "")}
      />
      <Input
        aria-label={`${label} time in UTC`}
        value={time}
        pattern="([01][0-9]|2[0-3]):[0-5][0-9]"
        placeholder="HH:MM"
        required={required}
        onChange={(event) => {
          setTime(event.target.value);
          if (date) onChange(`${date}T${event.target.value}:00Z`);
        }}
      />
      <Select
        label={`${label} timezone`}
        value="UTC"
        options={[{ value: "UTC", label: "UTC" }]}
      />
    </div>
  );
}
