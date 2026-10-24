<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    FolderGit2,
    KeyRound,
    Newspaper,
    Package,
    ScrollText,
    Settings,
    ShieldCheck,
} from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as auditIndex } from '@/routes/audit';
import { edit as integrationsEdit } from '@/routes/integrations';
import { index as newsIndex } from '@/routes/news';
import { index as packagesIndex } from '@/routes/packages';
import { index as repositoriesIndex } from '@/routes/repositories';
import { index as tokensIndex } from '@/routes/tokens';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Service health',
        href: dashboard(),
        icon: ShieldCheck,
    },
    {
        title: 'Packages',
        href: packagesIndex(),
        icon: Package,
    },
    {
        title: 'Repositories',
        href: repositoriesIndex(),
        icon: FolderGit2,
    },
    {
        title: 'News',
        href: newsIndex(),
        icon: Newspaper,
    },
    {
        title: 'Tokens',
        href: tokensIndex(),
        icon: KeyRound,
    },
    {
        title: 'Audit log',
        href: auditIndex(),
        icon: ScrollText,
    },
    {
        title: 'Settings',
        href: integrationsEdit(),
        icon: Settings,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
