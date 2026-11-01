import { DetailScreen, type DetailProps } from "@/components/staff/detail";
import { Card } from "@/components/ui/card";
export default function Member(props: DetailProps & { permissions: string[] }) {
  return (
    <DetailScreen {...props}>
      <Card className="p-5">
        <h2 className="mb-4 font-semibold">Role permissions</h2>
        <ul className="grid gap-2 text-sm sm:grid-cols-2">
          {props.permissions.map((ability) => (
            <li key={ability}>{ability}</li>
          ))}
        </ul>
      </Card>
    </DetailScreen>
  );
}
