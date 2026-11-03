import {
  RecordsScreen,
  type RecordsProps,
} from "@/components/staff/records-screen";
import { DetailFields, type DetailField } from "@/components/staff/detail";

export default function Subscriptions({
  pricing,
  ...records
}: RecordsProps & { pricing: { title: string; fields: DetailField[] } }) {
  return (
    <RecordsScreen {...records}>
      <DetailFields {...pricing} />
    </RecordsScreen>
  );
}
