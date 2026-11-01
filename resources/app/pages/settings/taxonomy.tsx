import { useState } from "react";
import { useForm } from "@inertiajs/react";
import {
  RecordsScreen,
  type RecordsProps,
  type RecordRow,
} from "@/components/staff/records-screen";
import { FormPanel } from "@/components/staff/form-panel";
import { Field } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogTitle,
  DialogDescription,
  DialogDismiss,
} from "@/components/ui/dialog";
type Genre = {
  name: string;
  slug: string;
  position: number;
  archived: boolean;
  version: number;
};
type GenreRow = RecordRow & { genre: Genre; edit_url: string | null };
function GenreForm({
  genre,
  url,
  creating,
  close,
}: {
  genre: Genre;
  url: string;
  creating: boolean;
  close: () => void;
}) {
  const form = useForm(genre);
  return (
    <FormPanel
      title={creating ? "Create genre" : "Edit genre"}
      busy={form.processing}
      errors={form.errors}
      onSubmit={(event) => {
        event.preventDefault();
        form.submit(creating ? "post" : "patch", url, {
          preserveScroll: true,
          onSuccess: close,
        });
      }}
    >
      <Field label="Name">
        <Input
          required
          minLength={2}
          maxLength={80}
          value={form.data.name}
          onValueChange={(value) => form.setData("name", value)}
        />
      </Field>
      <Field label="Slug">
        <Input
          required
          pattern="[a-z0-9]+(-[a-z0-9]+)*"
          maxLength={80}
          value={form.data.slug}
          onValueChange={(value) => form.setData("slug", value)}
        />
      </Field>
      <Field label="Display order">
        <Input
          required
          type="number"
          min={0}
          max={10000}
          value={form.data.position}
          onChange={(event) =>
            form.setData("position", Number(event.target.value))
          }
        />
      </Field>
      <label className="flex items-center gap-3 text-sm">
        <Checkbox
          checked={form.data.archived}
          onCheckedChange={(value) => form.setData("archived", Boolean(value))}
        />
        Archived
      </label>
    </FormPanel>
  );
}
export default function Taxonomy(
  props: Omit<RecordsProps, "rows"> & {
    rows: GenreRow[];
    store_url: string | null;
    categories: string[];
  },
) {
  const [editing, setEditing] = useState<GenreRow | "new" | null>(null);
  const blank: Genre = {
    name: "",
    slug: "",
    position: 0,
    archived: false,
    version: 0,
  };
  return (
    <>
      <RecordsScreen
        {...props}
        renderAction={(row) => {
          const genre = props.rows.find((item) => item.id === row.id)!;
          return genre.edit_url ? (
            <Button
              variant="secondary"
              size="sm"
              onClick={() => setEditing(genre)}
            >
              Edit
            </Button>
          ) : null;
        }}
      >
        <div className="flex flex-wrap gap-2">
          {props.store_url && (
            <Button onClick={() => setEditing("new")}>Create genre</Button>
          )}
        </div>
        <p className="text-xs text-muted-foreground">
          Categories:{" "}
          {props.categories
            .map((value) => value.replaceAll("_", " "))
            .join(", ")}
        </p>
      </RecordsScreen>
      <Dialog
        open={editing !== null}
        onOpenChange={(open) => {
          if (!open) setEditing(null);
        }}
      >
        <DialogContent>
          <DialogDismiss />
          <DialogTitle>Genre details</DialogTitle>
          <DialogDescription className="mb-5">
            Keep referenced genres; archive them when no longer offered.
          </DialogDescription>
          {editing && (
            <GenreForm
              genre={editing === "new" ? blank : editing.genre}
              url={editing === "new" ? props.store_url! : editing.edit_url!}
              creating={editing === "new"}
              close={() => setEditing(null)}
            />
          )}
        </DialogContent>
      </Dialog>
    </>
  );
}
