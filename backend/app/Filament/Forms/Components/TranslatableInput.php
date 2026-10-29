<?php

namespace App\Filament\Forms\Components;

use App\Support\Languages;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Fieldset;
use Illuminate\Database\Eloquent\Model;

/**
 * One translated field (spatie/laravel-translatable) as an input per
 * language — English, हिन्दी, ગુજરાતી — grouped under one label.
 *
 * The group's state path is the attribute itself, so the form state is
 * ['en' => …, 'hi' => …, 'gu' => …]; saving that array onto the model
 * stores every language at once. English is the fallback the apps show
 * when a translation is blank, so it alone can be required.
 */
class TranslatableInput
{
    /**
     * @param  Closure(string $language): Field  $input  builds one input
     */
    public static function make(
        string $attribute,
        string $label,
        Closure $input,
        bool $required = false,
        int $columns = 3,
    ): Fieldset {
        $fields = [];

        foreach (Languages::SUPPORTED as $code => $name) {
            $fields[] = $input($code)
                ->label($name)
                ->required($required && $code === Languages::ENGLISH)
                ->helperText($code === Languages::ENGLISH ? null : 'Optional — English is shown if left blank.');
        }

        return Fieldset::make($label)
            ->statePath($attribute)
            ->columns($columns)
            ->schema($fields)
            // The form is filled from the raw attributes, where this column
            // is still the stored JSON string; load it as one value per
            // language instead.
            ->afterStateHydrated(function (Fieldset $component, ?Model $record) use ($attribute): void {
                if ($record !== null && method_exists($record, 'getTranslations')) {
                    $component->state($record->getTranslations($attribute));
                }
            });
    }
}
