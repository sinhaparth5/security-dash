<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { KeyRound, ScrollText, ShieldCheck } from '@lucide/vue';
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
import { index as tokensIndex } from '@/routes/tokens';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Service health',
        href: dashboard(),
        icon: ShieldCheck,
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
