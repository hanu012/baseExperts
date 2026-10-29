<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Enums\ApprovalStatus;
use App\Enums\UserRole;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('New user'),
        ];
    }

    /**
     * One tab per role, plus the approval queue, each with its count — the
     * same "cards that filter" shape as the other people screens.
     */
    public function getTabs(): array
    {
        // Admins are not on this screen (Super Admin → Admins), so they are
        // not in any count either.
        $users = fn () => User::query()->where('role', '!=', UserRole::Admin);

        $tabs = [
            'all' => Tab::make('All users')->badge($users()->count()),
        ];

        foreach ([
            'salesman' => [UserRole::Salesman, 'Salesmen'],
            'vendor' => [UserRole::Vendor, 'Vendors'],
            'customer' => [UserRole::Customer, 'Customers'],
        ] as $key => [$role, $label]) {
            $tabs[$key] = Tab::make($label)
                ->badge(User::query()->where('role', $role)->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('role', $role));
        }

        $tabs['pending'] = Tab::make('Pending approval')
            ->badge($users()->where('approval_status', ApprovalStatus::Pending)->count())
            ->badgeColor('warning')
            ->modifyQueryUsing(fn (Builder $query) => $query->where('approval_status', ApprovalStatus::Pending));

        return $tabs;
    }

    public function getSubheading(): ?HtmlString
    {
        return new HtmlString(
            'Creating a user generates a <strong>temporary password shown once</strong> — '
            .'copy it before dismissing the notification, because it cannot be recovered. '
            .'Accounts created here are approved straight away, since you are vouching for them. '
            .'<strong>Deleting releases the email address</strong> so the person can sign up '
            .'again, signs out all their devices, and keeps their history; it can be undone '
            .'from the account page unless someone else has since taken the address.'
        );
    }
}
