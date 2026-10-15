<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    FolderGit2,
    LayoutGrid,
    PackageSearch,
    Store,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
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
import { index as panelProducts } from '@/routes/panel/products';
import { index as panelSellers } from '@/routes/panel/sellers';
import { apply as sellerApply, status as sellerStatus } from '@/routes/seller';
import type { NavItem } from '@/types';

const page = usePage();

// Panel jest tylko dla adminów (Gate `panel`) — innym nie pokazujemy linku, który skończyłby się 403.
const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    // Pulpit `/seller` dochodzi w kolejnym kroku; do tego czasu portal to ekran statusu.
    ...(page.props.auth.user
        ? [
              page.props.auth.seller
                  ? {
                        title: 'Portal sprzedawcy',
                        href: sellerStatus(),
                        icon: Store,
                    }
                  : {
                        title: 'Zostań sprzedawcą',
                        href: sellerApply(),
                        icon: Store,
                    },
          ]
        : []),
    ...(page.props.auth.user?.is_admin
        ? [
              { title: 'Panel', href: panelProducts(), icon: PackageSearch },
              { title: 'Sprzedawcy', href: panelSellers(), icon: Users },
          ]
        : []),
]);

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
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
            <NavFooter :items="footerNavItems" />
            <!-- /search jest publiczne — gość nie ma `auth.user`, a NavUser go wymaga. -->
            <NavUser v-if="$page.props.auth.user" />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
