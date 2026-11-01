import {
  RecordsScreen,
  type RecordsProps,
} from "@/components/staff/records-screen";
import {
  WorkerTable,
  type Worker,
} from "@/components/staff/operational-health";
export default function Media(props: RecordsProps & { cleanup: Worker[] }) {
  return (
    <RecordsScreen {...props}>
      <WorkerTable workers={props.cleanup} />
    </RecordsScreen>
  );
}
