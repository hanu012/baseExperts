<?php

namespace App\Enums;

/**
 * Every ability a sub-admin can be granted (SPEC section 5.16).
 *
 * ONE registry, read by the policies, the admin checkbox UI and the tests
 * alike — so an ability cannot exist in a policy but be ungrantable in the
 * UI, or be grantable but checked nowhere.
 *
 * Naming is `module.action`, where action matches the policy method name
 * exactly (`viewAny`, `view`, `create`, `update`, `delete`, `restore`). That
 * correspondence is what lets a policy method resolve its own permission
 * string without a lookup table.
 */
enum Permission: string
{
    // Master data
    case CategoriesViewAny = 'categories.viewAny';
    case CategoriesCreate = 'categories.create';
    case CategoriesUpdate = 'categories.update';
    // Covers subcategories too. Only ever deletes unused records — see SafeDelete.
    case CategoriesDelete = 'categories.delete';

    case ZonesViewAny = 'zones.viewAny';
    case ZonesCreate = 'zones.create';
    case ZonesUpdate = 'zones.update';
    case ZonesDelete = 'zones.delete';

    case PlansViewAny = 'plans.viewAny';
    case PlansCreate = 'plans.create';
    case PlansUpdate = 'plans.update';

    // People
    case UsersViewAny = 'users.viewAny';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersDelete = 'users.delete';
    case UsersRestore = 'users.restore';

    case VendorsViewAny = 'vendors.viewAny';
    case VendorsVerify = 'vendors.verify';

    case MediaViewAny = 'media.viewAny';
    case MediaModerate = 'media.moderate';

    case ReviewsViewAny = 'reviews.viewAny';
    case ReviewsHide = 'reviews.hide';

    case ReportsViewAny = 'reports.viewAny';

    case LeadsViewAny = 'leads.viewAny';

    case CommissionsViewAny = 'commissions.viewAny';
    case CommissionsMarkPaid = 'commissions.markPaid';

    case PaymentsViewAny = 'payments.viewAny';
    case PaymentsVerify = 'payments.verify';

    // Content
    case BannersViewAny = 'banners.viewAny';
    case BannersCreate = 'banners.create';
    case BannersUpdate = 'banners.update';
    case BannersDelete = 'banners.delete';

    case PagesViewAny = 'pages.viewAny';
    case PagesCreate = 'pages.create';
    case PagesUpdate = 'pages.update';

    // System
    case SettingsViewAny = 'settings.viewAny';
    case SettingsUpdate = 'settings.update';

    // No logs.* here any more: the API error log, activity log, system
    // settings and admin management live in /super-admin, which is
    // super-admins only — nothing there is grantable to a sub-admin.

    /**
     * Grants everything. Held only by super-admins.
     */
    public const WILDCARD = '*';

    /**
     * Grouped for the admin checkbox UI.
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (self::cases() as $permission) {
            [$module] = explode('.', $permission->value, 2);

            $groups[ucfirst($module)][$permission->value] = $permission->label();
        }

        return $groups;
    }

    public function label(): string
    {
        [$module, $action] = explode('.', $this->value, 2);

        return match ($action) {
            'viewAny' => 'View '.$module,
            'create' => 'Create',
            'update' => 'Edit',
            'delete' => 'Delete',
            'restore' => 'Restore',
            default => ucfirst($action),
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
