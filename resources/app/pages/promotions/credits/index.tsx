import {
  RecordsScreen,
  type RecordsProps,
} from "@/components/staff/records-screen";
import { DetailFields } from "@/components/staff/detail";
export default function Credits(
  props: RecordsProps & {
    balances: {
      available_cents: number;
      reserved_cents: number;
      charged_cents: number;
      returned_cents: number;
    };
  },
) {
  return (
    <RecordsScreen {...props}>
      <DetailFields
        title="Recorded balances for selected owner / period · USD cents"
        fields={[
          { label: "Available", value: props.balances.available_cents },
          { label: "Reserved", value: props.balances.reserved_cents },
          { label: "Charged", value: props.balances.charged_cents },
          { label: "Returned", value: props.balances.returned_cents },
        ]}
      />
    </RecordsScreen>
  );
}
