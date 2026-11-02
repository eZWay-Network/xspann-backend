import {
  RecordsScreen,
  type RecordsProps,
} from "@/components/staff/records-screen";

export default function Audio(props: RecordsProps) {
  return (
    <RecordsScreen
      {...props}
      emptyState={{
        noun: "audio",
        description:
          "Audio appears here when a creator uploads a track or creates an original sound.",
      }}
    />
  );
}
