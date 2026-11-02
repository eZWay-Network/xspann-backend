import {
  useEffect,
  useRef,
  useState,
  type KeyboardEvent,
  type RefObject,
} from "react";
import { Link, router } from "@inertiajs/react";
import { Search, ArrowUpRight } from "lucide-react";
import { useApp } from "@/contexts/app";
import { Dialog, DialogContent, DialogTitle, DialogDismiss } from "./ui/dialog";
import { Input } from "./ui/input";
export function NavigationSearch({
  open,
  onOpenChange,
  returnFocus,
}: {
  returnFocus: RefObject<HTMLElement | null>;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { menuLinks } = useApp();
  const navigate = (path: string) => router.visit(path);
  const [query, setQuery] = useState("");
  const input = useRef<HTMLInputElement>(null);
  const links = useRef<(HTMLAnchorElement | null)[]>([]);
  const results = menuLinks.filter((item) =>
    `${item.parent} ${item.title}`
      .toLowerCase()
      .includes(query.trim().toLowerCase()),
  );
  useEffect(() => {
    if (!open) setQuery("");
  }, [open]);
  function navigateResults(event: KeyboardEvent, index: number) {
    if (event.key === "Enter" && index === -1 && results[0]) {
      event.preventDefault();
      navigate(results[0].path);
      onOpenChange(false);
      return;
    }
    if (event.key !== "ArrowDown" && event.key !== "ArrowUp") return;
    event.preventDefault();
    const next = index + (event.key === "ArrowDown" ? 1 : -1);
    if (next < 0 || next >= results.length) input.current?.focus();
    else links.current[next]?.focus();
  }
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="top-[15dvh] flex max-h-[min(36rem,80dvh)] translate-y-0 flex-col overflow-hidden p-0 sm:p-0"
        initialFocus={input}
        finalFocus={returnFocus}
      >
        <DialogTitle className="sr-only">Search navigation</DialogTitle>
        <div className="relative flex shrink-0 items-center gap-3 border-b px-5 pr-14">
          <Search className="size-4 shrink-0 text-muted-foreground" />
          <Input
            ref={input}
            variant="plain"
            aria-label="Search pages"
            placeholder="Search pages and settings…"
            value={query}
            onValueChange={setQuery}
            onKeyDown={(event) => navigateResults(event, -1)}
          />
          <DialogDismiss />
        </div>
        <div className="min-h-0 overflow-y-auto overscroll-contain p-2">
          <p className="px-3 py-2 text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
            {query ? "Results" : "Jump to"}
          </p>
          {results.map((item, index) => (
            <Link
              ref={(element: HTMLAnchorElement | null) => {
                links.current[index] = element;
              }}
              key={item.path}
              aria-label={`${item.title}, ${item.parent}`}
              href={item.path}
              onClick={() => onOpenChange(false)}
              onKeyDown={(event) => navigateResults(event, index)}
              className="focus-ring flex items-center gap-3 rounded-md px-3 py-2.5 hover:bg-muted focus:bg-muted focus-visible:ring-inset"
            >
              <item.icon className="size-4 shrink-0 text-muted-foreground" />
              <span className="flex-1 text-sm">
                {item.title}
                <span className="ml-2 text-xs text-muted-foreground">
                  {item.parent}
                </span>
              </span>
              <ArrowUpRight className="size-3.5 text-muted-foreground" />
            </Link>
          ))}
          {!results.length && (
            <p role="status" className="p-8 text-center text-description">
              No pages found. Try “profile” or “security”.
            </p>
          )}
        </div>
        <div className="flex shrink-0 items-center gap-4 border-t bg-background/70 px-4 py-2.5 text-[10px] text-muted-foreground">
          <span className="flex items-center gap-1">
            <kbd className="keyboard-key">↑</kbd>
            <kbd className="keyboard-key">↓</kbd> navigate
          </span>
          <span className="flex items-center gap-1">
            <kbd className="keyboard-key">↵</kbd> open
          </span>
          <span className="flex items-center gap-1">
            <kbd className="keyboard-key">Esc</kbd> close
          </span>
        </div>
      </DialogContent>
    </Dialog>
  );
}
