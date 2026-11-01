import { router } from "@inertiajs/react";
import { ChevronDown, LogOut, UserRound, ShieldCheck } from "lucide-react";
import { useApp } from "@/contexts/app";
import { Avatar } from "./ui/avatar";
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
} from "./ui/dropdown-menu";
export function AccountMenu({ onLogout }: { onLogout: () => void }) {
  const { auth, urls } = useApp();
  const user = auth!.user;
  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        className="focus-ring flex items-center gap-2.5 rounded-md"
        aria-label="Account menu"
      >
        <Avatar
          name={user.name}
          src={user.avatar_url ?? undefined}
          className="size-8"
        />
        <ChevronDown className="hidden size-3.5 text-muted-foreground sm:block" />
      </DropdownMenuTrigger>
      <DropdownMenuContent>
        <div className="flex items-center px-2">
          <Avatar name={user.name} className="size-8" />
          <div className="max-w-64 px-3 py-2">
            <p className="truncate text-sm font-medium">{user.name}</p>
            <p className="mt-0.5 truncate text-xs text-muted-foreground">
              @{user.username} · {auth!.role.replaceAll("_", " ")}
            </p>
          </div>
        </div>
        <DropdownMenuSeparator />
        <DropdownMenuItem onClick={() => router.visit(urls.profile)}>
          <UserRound />
          My profile
        </DropdownMenuItem>
        <DropdownMenuItem onClick={() => router.visit(urls.security)}>
          <ShieldCheck />
          Security & sessions
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem onClick={onLogout}>
          <LogOut />
          Sign out
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
