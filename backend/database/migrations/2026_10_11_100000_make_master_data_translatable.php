<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Master-data text the apps show becomes per-language (en / hi / gu),
 * stored by spatie/laravel-translatable as a JSON object in the column.
 *
 * Text, not the JSON column type: on MariaDB JSON is a LONGTEXT with a
 * json_valid() CHECK, so the existing plain values would fail it before
 * they could be wrapped. Each existing value becomes its English entry.
 */
return new class extends Migration
{
    /** table => [column => [nullable, original type]] */
    private const COLUMNS = [
        'categories' => ['name' => [false, 'string']],
        'subcategories' => ['name' => [false, 'string']],
        'plans' => ['name' => [false, 'string'], 'description' => [true, 'text']],
        'banners' => ['title' => [true, 'string']],
        'cms_pages' => ['title' => [false, 'string'], 'body' => [true, 'longText']],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column => [$nullable, $type]) {
                    $definition = $type === 'longText'
                        ? $blueprint->longText($column)
                        : $blueprint->text($column);

                    $definition->nullable($nullable)->change();
                }
            });

            DB::table($table)->orderBy('id')->each(function (object $row) use ($table, $columns) {
                $update = [];

                foreach (array_keys($columns) as $column) {
                    $value = $row->{$column};

                    if ($value === null || $this->isTranslationJson($value)) {
                        continue;
                    }

                    $update[$column] = json_encode(['en' => $value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                if ($update !== []) {
                    DB::table($table)->where('id', $row->id)->update($update);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            DB::table($table)->orderBy('id')->each(function (object $row) use ($table, $columns) {
                $update = [];

                foreach (array_keys($columns) as $column) {
                    $value = $row->{$column};

                    if ($value !== null && $this->isTranslationJson($value)) {
                        $translations = json_decode($value, true);
                        $update[$column] = $translations['en'] ?? reset($translations) ?: null;
                    }
                }

                if ($update !== []) {
                    DB::table($table)->where('id', $row->id)->update($update);
                }
            });

            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column => [$nullable, $type]) {
                    $definition = match ($type) {
                        'string' => $blueprint->string($column),
                        'longText' => $blueprint->longText($column),
                        default => $blueprint->text($column),
                    };

                    $definition->nullable($nullable)->change();
                }
            });
        }
    }

    private function isTranslationJson(string $value): bool
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) && $decoded !== [] && array_is_list($decoded) === false;
    }
};
