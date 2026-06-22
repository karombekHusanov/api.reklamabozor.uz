<?php

namespace Database\Seeders;

use App\Enums\CategoryType;
use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Idempotent: keyed on (name_uz, type) so re-running won't duplicate.
     */
    public function run(): void
    {
        $agentCategories = [
            ['name_uz' => 'Tashqi reklama', 'name_ru' => 'Наружная реклама'],
            ['name_uz' => 'Raqamli reklama', 'name_ru' => 'Цифровая реклама'],
            ['name_uz' => 'Transport reklamasi', 'name_ru' => 'Транспортная реклама'],
            ['name_uz' => 'Telegram kanallari', 'name_ru' => 'Telegram-каналы'],
            ['name_uz' => 'Influencer marketing', 'name_ru' => 'Инфлюенсер-маркетинг'],
            ['name_uz' => 'Televidenie reklamasi', 'name_ru' => 'Реклама на ТВ'],
            ['name_uz' => 'Radio reklama', 'name_ru' => 'Реклама на радио'],
            ['name_uz' => 'Bosma reklama', 'name_ru' => 'Печатная реклама'],
            ['name_uz' => 'SMM', 'name_ru' => 'SMM'],
            ['name_uz' => 'Banner reklama', 'name_ru' => 'Баннерная реклама'],
        ];

        foreach ($agentCategories as $index => $category) {
            Category::updateOrCreate(
                ['name_uz' => $category['name_uz'], 'type' => CategoryType::Agent],
                [
                    'name_ru' => $category['name_ru'],
                    'is_active' => true,
                    'sort_order' => $index,
                ],
            );
        }

        $designerCategories = [
            ['name_uz' => 'Logotip dizayni', 'name_ru' => 'Дизайн логотипа'],
            ['name_uz' => 'Brending', 'name_ru' => 'Брендинг'],
            ['name_uz' => 'Banner dizayni', 'name_ru' => 'Дизайн баннеров'],
            ['name_uz' => 'Motion dizayn', 'name_ru' => 'Моушн-дизайн'],
        ];

        foreach ($designerCategories as $index => $category) {
            Category::updateOrCreate(
                ['name_uz' => $category['name_uz'], 'type' => CategoryType::Designer],
                [
                    'name_ru' => $category['name_ru'],
                    'is_active' => true,
                    'sort_order' => $index,
                ],
            );
        }
    }
}
