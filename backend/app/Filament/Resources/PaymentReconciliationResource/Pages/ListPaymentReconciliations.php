<?php

namespace App\Filament\Resources\PaymentReconciliationResource\Pages;

use App\Filament\Resources\PaymentReconciliationResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentReconciliations extends ListRecords
{
    protected static string $resource = PaymentReconciliationResource::class;

    protected function getHeaderActions(): array
    {
        // No CreateAction — payments aren't created through this queue.
        return [];
    }
}
