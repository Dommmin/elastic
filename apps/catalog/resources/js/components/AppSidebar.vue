<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    FolderGit2,
    LayoutGrid,
    PackageCheck,
    PackageSearch,
    Scale,
    ShoppingCart,
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
import { hasSellerPortal } from '@/lib/sellerStatus';
import { dashboard } from '@/routes';
import { index as cartIndex } from '@/routes/cart';
import { index as ordersIndex } from '@/routes/orders';
import { priceChanges as panelPriceChanges } from '@/routes/panel/moderation';
import { index as panelProducts } from '@/routes/panel/products';
import { index as panelSellers } from '@/routes/panel/sellers';
import {
    apply as sellerApply,
    dashboard as sellerDashboard,
    status as sellerStatus,
} from '@/routes/seller';
import { index as sellerOrdersIndex } from '@/routes/seller/orders';
import type { NavItem } from '@/types';

const page = usePage();

// Panel jest tylko dla adminów (Gate `panel`) — innym nie pokazujemy linku, który skończyłby się 403.
const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    ...(page.props.auth.user
        ? [
              {
                  title: 'Koszyk',
                  href: cartIndex(),
                  icon: ShoppingCart,
                  badge: page.props.cart.count || undefined,
              },
              {
                  title: 'Moje zamówienia',
                  href: ordersIndex(),
                  icon: PackageCheck,
              },
              page.props.auth.seller
                  ? {
                        title: 'Portal sprzedawcy',
                        href: hasSellerPortal(page.props.auth.seller.status)
                            ? sellerDashboard()
                            : sellerStatus(),
                        icon: Store,
                    }
                  : {
                        title: 'Zostań sprzedawcą',
                        href: sellerApply(),
                        icon: Store,
                    },
              ...(page.props.auth.seller &&
              hasSellerPortal(page.props.auth.seller.status)
                  ? [
                        {
                            title: 'Zamówienia sprzedawcy',
                            href: sellerOrdersIndex(),
                            icon: PackageCheck,
                        },
                    ]
                  : []),
          ]
        : []),
    ...(page.props.auth.user?.is_admin
        ? [
              { title: 'Panel', href: panelProducts(), icon: PackageSearch },
              { title: 'Sprzedawcy', href: panelSellers(), icon: Users },
              {
                  title: 'Zmiany cen',
                  href: panelPriceChanges(),
                  icon: Scale,
                  badge:
                      page.props.moderation?.pending_price_changes ?? undefined,
              },
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
