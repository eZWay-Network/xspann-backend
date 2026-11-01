import { DetailScreen, type DetailProps } from "@/components/staff/detail";
import { Disclosure } from "@/components/staff/disclosure";
export default function UserDetail(
  props: DetailProps & { contact_url: string | null },
) {
  return (
    <DetailScreen {...props}>
      {props.contact_url && <Disclosure url={props.contact_url} contact />}
    </DetailScreen>
  );
}
