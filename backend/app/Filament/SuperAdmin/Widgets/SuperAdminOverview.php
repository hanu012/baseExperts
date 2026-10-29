<?php

namespace App\Filament\SuperAdmin\Widgets;

use App\Enums\UserRole;
use App\Filament\SuperAdmin\Pages\SystemHealthPage;
use App\Filament\SuperAdmin\Resources\ActivityLogResource;
use App\Filament\SuperAdmin\Resources\AdminResource;
use App\Models\ApiErrorLog;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\SystemHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The Super Admin dashboard: four numbers, each a link to the screen behind it. */
class SuperAdminOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $admins = User::query()->where('role', UserRole::Admin)->get(['id', 'role', 'permissions', 'two_factor_confirmed_at', 'two_factor_secret']);
        $superAdmins = $admins->filter->isSuperAdmin();
        $withoutTwoFactor = $superAdmins->reject->hasTwoFactorEnabled()->count();

        $problems = collect(app(SystemHealth::class)->checks())->where('status', SystemHealth::FAIL)->count();
        $failedJobs = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;
        $errors = ApiErrorLog::query()->where('status', '>=', 500)->where('created_at', '>=', now()->subDay())->count();

        return [
            Stat::make('Admins', $admins->count())
                ->description($superAdmins->count().' super-admin'.($superAdmins->count() === 1 ? '' : 's')
                    .($withoutTwoFactor > 0 ? " · {$withoutTwoFactor} without two-step login yet" : ''))
                ->color($withoutTwoFactor > 0 ? 'warning' : 'primary')
                ->url(AdminResource::getUrl('index')),

            Stat::make('System health', $problems === 0 ? 'All good' : $problems.' problem'.($problems === 1 ? '' : 's'))
                ->description($failedJobs > 0 ? "{$failedJobs} failed background jobs" : 'Scheduler, queue, storage and services')
                ->color($problems === 0 ? 'success' : 'danger')
                ->url(SystemHealthPage::getUrl()),

            Stat::make('Server errors (24 h)', $errors)
                ->description('Recorded while the API error log is on')
                ->color($errors > 0 ? 'danger' : 'success'),

            Stat::make('Changes today', AuditLog::query()->where('created_at', '>=', now()->startOfDay())->count())
                ->description('From the activity log')
                ->url(ActivityLogResource::getUrl('index')),
        ];
    }
}
