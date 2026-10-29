<?php

namespace App\Filament\Resources\PlanResource\Pages;

use App\Filament\Resources\PlanResource;
use App\Models\Plan;
use Filament\Resources\Pages\CreateRecord;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    /**
     * The form collects rupees; the column stores paise.
     *
     * Conversion goes through Plan::rupeesToPaise(), which parses the decimal
     * as a string — multiplying a float would turn Rs 1.15 into 114 paise.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['price_paise'] = Plan::rupeesToPaise($data['price_rupees'] ?? 0);
        unset($data['price_rupees']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
