<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Authz;

/**
 * The single source of truth for the platform's granular permissions.
 *
 * Permissions are keyed `module.action` and intentionally go well beyond CRUD -
 * each meaningful feature of an admin module is its own permission so roles can
 * be composed as granularly as the business needs. The catalog is seeded into
 * the `permissions` table by migration and is also used by the API to validate
 * role definitions and by the frontend (via an endpoint) to render the
 * permission matrix.
 *
 * Adding a capability = add a key here + reseed (the seeder is idempotent).
 */
final class PermissionCatalog
{
    /**
     * module slug => [ label, permissions: [ key => label ] ]
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public static function modules(): array
    {
        return [
            'dashboard' => [
                'label' => 'Dashboard',
                'permissions' => [
                    'dashboard.view' => 'View dashboard & metrics',
                ],
            ],
            'orders' => [
                'label' => 'Orders',
                'permissions' => [
                    'orders.view' => 'View orders list',
                    'orders.view_detail' => 'View order detail',
                    'orders.export' => 'Export orders',
                    'orders.override_status' => 'Override order status',
                    'orders.update_item_status' => 'Update item status',
                    'orders.cancel' => 'Cancel orders',
                    'orders.refund' => 'Refund orders',
                ],
            ],
            'logistics' => [
                'label' => 'Logistics',
                'permissions' => [
                    'logistics.view' => 'View delivery board',
                    'logistics.manage_delivery' => 'Manage deliveries',
                ],
            ],
            'sales' => [
                'label' => 'Sales',
                'permissions' => [
                    'sales.view' => 'View sales',
                    'sales.export' => 'Export sales',
                ],
            ],
            'vendors' => [
                'label' => 'Vendors',
                'permissions' => [
                    'vendors.view' => 'View vendors',
                    'vendors.view_detail' => 'View vendor detail',
                    'vendors.create' => 'Create vendors',
                    'vendors.edit' => 'Edit vendors',
                    'vendors.approve' => 'Approve / activate vendors',
                    'vendors.suspend' => 'Suspend / deactivate vendors',
                    'vendors.view_compliance' => 'View compliance documents',
                    'vendors.review_compliance' => 'Approve / reject compliance',
                    'vendors.impersonate' => 'Sign in as a vendor (impersonate)',
                    'vendors.export' => 'Export vendors',
                ],
            ],
            'products' => [
                'label' => 'Products',
                'permissions' => [
                    'products.view' => 'View products',
                    'products.create' => 'Create products',
                    'products.edit' => 'Edit products',
                    'products.delete' => 'Delete products',
                    'products.feature' => 'Feature / unfeature products',
                    'products.manage_inventory' => 'Manage inventory',
                ],
            ],
            'catalog' => [
                'label' => 'Catalog taxonomy',
                'permissions' => [
                    'catalog.brands_view' => 'View brands',
                    'catalog.brands_manage' => 'Create / edit / delete brands',
                    'catalog.categories_view' => 'View categories',
                    'catalog.categories_manage' => 'Create / edit / delete categories',
                    'catalog.collections_view' => 'View collections',
                    'catalog.collections_manage' => 'Create / edit / delete collections',
                    'catalog.campaigns_view' => 'View campaigns',
                    'catalog.campaigns_manage' => 'Create / edit / delete campaigns',
                ],
            ],
            'coupons' => [
                'label' => 'Coupons',
                'permissions' => [
                    'coupons.view' => 'View coupons',
                    'coupons.create' => 'Create coupons',
                    'coupons.edit' => 'Edit coupons',
                    'coupons.delete' => 'Delete coupons',
                ],
            ],
            'gift_cards' => [
                'label' => 'Gift cards',
                'permissions' => [
                    'gift_cards.view' => 'View gift cards',
                    'gift_cards.create' => 'Create gift cards',
                    'gift_cards.edit' => 'Edit gift cards',
                    'gift_cards.delete' => 'Void gift cards',
                    'gift_cards.adjust_balance' => 'Adjust balances',
                ],
            ],
            'payouts' => [
                'label' => 'Payouts & finance',
                'permissions' => [
                    'payouts.view' => 'View payouts',
                    'payouts.process' => 'Process payouts',
                    'payouts.export' => 'Export payouts',
                    'payouts.view_transactions' => 'View transactions',
                    'payouts.view_commissions' => 'View commissions',
                ],
            ],
            'returns' => [
                'label' => 'Returns',
                'permissions' => [
                    'returns.view' => 'View returns',
                    'returns.approve' => 'Approve returns',
                    'returns.deny' => 'Deny returns',
                    'returns.refund' => 'Refund returns',
                ],
            ],
            'disputes' => [
                'label' => 'Disputes',
                'permissions' => [
                    'disputes.view' => 'View disputes',
                    'disputes.resolve' => 'Resolve disputes',
                ],
            ],
            'chat' => [
                'label' => 'Chat',
                'permissions' => [
                    'chat.moderate' => 'Chat moderation',
                ],
            ],
            'notifications' => [
                'label' => 'Notifications',
                'permissions' => [
                    'notifications.view' => 'View notifications',
                    'notifications.send' => 'Send notifications',
                    'notifications.manage_templates' => 'Manage templates',
                ],
            ],
            'reports' => [
                'label' => 'Reports',
                'permissions' => [
                    'reports.view' => 'View reports',
                    'reports.export' => 'Export reports',
                ],
            ],
            'users' => [
                'label' => 'Staff & roles',
                'permissions' => [
                    'users.view' => 'View staff users',
                    'users.create' => 'Create staff users',
                    'users.edit' => 'Edit staff users',
                    'users.deactivate' => 'Activate / deactivate staff',
                    'users.manage_roles' => 'Assign roles to staff',
                    'roles.view' => 'View roles',
                    'roles.create' => 'Create roles',
                    'roles.edit' => 'Edit roles & permissions',
                    'roles.delete' => 'Delete roles',
                ],
            ],
            'settings' => [
                'label' => 'Settings',
                'permissions' => [
                    'settings.view' => 'View settings',
                    'settings.edit' => 'Edit settings',
                ],
            ],
            'audit' => [
                'label' => 'Audit log',
                'permissions' => [
                    'audit.view' => 'View the audit log',
                ],
            ],
        ];
    }

    /** Flat list of every permission key. @return list<string> */
    public static function allKeys(): array
    {
        $keys = [];
        foreach (self::modules() as $module) {
            foreach ($module['permissions'] as $key => $_label) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /** @return array<string, string> key => label */
    public static function flat(): array
    {
        $flat = [];
        foreach (self::modules() as $module) {
            foreach ($module['permissions'] as $key => $label) {
                $flat[$key] = $label;
            }
        }
        return $flat;
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::allKeys(), true);
    }

    public static function moduleOf(string $key): string
    {
        return explode('.', $key, 2)[0];
    }

    /**
     * System role presets. `super_admin` is special-cased to all permissions.
     *
     * @return array<string, array{name: string, description: string, permissions: list<string>}>
     */
    public static function systemRoles(): array
    {
        return [
            'super_admin' => [
                'name' => 'Super Admin',
                'description' => 'Full, unrestricted access to every module.',
                'permissions' => self::allKeys(),
            ],
            'operations' => [
                'name' => 'Operations',
                'description' => 'Day-to-day order, delivery, product and vendor operations.',
                'permissions' => [
                    'dashboard.view',
                    'orders.view', 'orders.view_detail', 'orders.export', 'orders.override_status',
                    'orders.update_item_status', 'orders.cancel',
                    'logistics.view', 'logistics.manage_delivery',
                    'products.view', 'products.create', 'products.edit', 'products.feature', 'products.manage_inventory',
                    'vendors.view', 'vendors.view_detail', 'vendors.approve',
                    'returns.view', 'returns.approve', 'returns.deny',
                    'sales.view',
                    'catalog.brands_view', 'catalog.brands_manage',
                    'catalog.categories_view', 'catalog.categories_manage',
                    'catalog.collections_view', 'catalog.collections_manage',
                    'catalog.campaigns_view', 'catalog.campaigns_manage',
                    'disputes.view', 'disputes.resolve',
                ],
            ],
            'finance' => [
                'name' => 'Finance',
                'description' => 'Payouts, refunds, sales and financial reporting.',
                'permissions' => [
                    'dashboard.view',
                    'sales.view', 'sales.export',
                    'payouts.view', 'payouts.process', 'payouts.export',
                    'payouts.view_transactions', 'payouts.view_commissions',
                    'orders.view', 'orders.view_detail', 'orders.refund', 'orders.export',
                    'returns.view', 'returns.refund',
                    'gift_cards.view', 'gift_cards.adjust_balance',
                    'reports.view', 'reports.export',
                ],
            ],
            'support' => [
                'name' => 'Support',
                'description' => 'Customer support: chat moderation, returns and read access to orders.',
                'permissions' => [
                    'dashboard.view',
                    'chat.moderate',
                    'orders.view', 'orders.view_detail',
                    'returns.view', 'returns.approve', 'returns.deny',
                    'vendors.view',
                    'notifications.view',
                    'disputes.view',
                ],
            ],
        ];
    }
}
