import { usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import { useOffline } from '@/components/offline/offline-provider';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { useIsMobile } from '@/hooks/use-mobile';
import type { User } from '@/types';

export function NavUser() {
    const { auth } = usePage().props;
    const { lease } = useOffline();
    const { state } = useSidebar();
    const isMobile = useIsMobile();

    const resolvedUser: User | null =
        auth.user ??
        (lease
            ? ({
                  id: lease.user_id,
                  name: lease.name,
                  email: lease.email,
                  timezone: lease.timezone,
                  email_verified_at: lease.last_verified_at,
                  whatsapp_number: lease.whatsapp_number_masked,
                  whatsapp_verified_at: lease.whatsapp_verified
                      ? lease.last_verified_at
                      : null,
                  whatsapp_enabled: lease.whatsapp_enabled,
                  reminder_quiet_start: '00:00',
                  reminder_quiet_end: '05:00',
                  created_at: lease.last_verified_at,
                  updated_at: lease.last_verified_at,
              } as User)
            : null);

    if (!resolvedUser) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            size="lg"
                            className="group text-sidebar-accent-foreground data-[state=open]:bg-sidebar-accent"
                            data-test="sidebar-menu-button"
                        >
                            <UserInfo user={resolvedUser} />
                            <ChevronsUpDown className="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        className="w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg"
                        align="end"
                        side={
                            isMobile
                                ? 'bottom'
                                : state === 'collapsed'
                                  ? 'left'
                                  : 'bottom'
                        }
                    >
                        <UserMenuContent user={resolvedUser} />
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
