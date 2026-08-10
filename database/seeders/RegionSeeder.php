<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;

class RegionSeeder extends Seeder
{
    /**
     * Idempotent: keyed on `code` so re-running won't duplicate.
     */
    public function run(): void
    {
        $roots = [
            ['code' => 'qoraqalpogiston-respublikasi', 'name_uz' => 'Qoraqalpogʻiston Respublikasi', 'name_ru' => 'Республика Каракалпакстан'],
            ['code' => 'andijon', 'name_uz' => 'Andijon', 'name_ru' => 'Андижан'],
            ['code' => 'buxoro', 'name_uz' => 'Buxoro', 'name_ru' => 'Бухара'],
            ['code' => 'fargona', 'name_uz' => 'Fargʻona', 'name_ru' => 'Фергана'],
            ['code' => 'jizzax', 'name_uz' => 'Jizzax', 'name_ru' => 'Джизак'],
            ['code' => 'xorazm', 'name_uz' => 'Xorazm', 'name_ru' => 'Хорезм'],
            ['code' => 'namangan', 'name_uz' => 'Namangan', 'name_ru' => 'Наманган'],
            ['code' => 'navoiy', 'name_uz' => 'Navoiy', 'name_ru' => 'Навои'],
            ['code' => 'qashqadaryo', 'name_uz' => 'Qashqadaryo', 'name_ru' => 'Кашкадарья'],
            ['code' => 'samarqand', 'name_uz' => 'Samarqand', 'name_ru' => 'Самарканд'],
            ['code' => 'sirdaryo', 'name_uz' => 'Sirdaryo', 'name_ru' => 'Сырдарья'],
            ['code' => 'surxondaryo', 'name_uz' => 'Surxondaryo', 'name_ru' => 'Сурхандарья'],
            ['code' => 'toshkent-viloyati', 'name_uz' => 'Toshkent viloyati', 'name_ru' => 'Ташкентская область'],
            ['code' => 'toshkent-shahri', 'name_uz' => 'Toshkent shahri', 'name_ru' => 'город Ташкент'],
        ];

        foreach ($roots as $index => $region) {
            Region::updateOrCreate(
                ['code' => $region['code']],
                [
                    'parent_id' => null,
                    'name_uz' => $region['name_uz'],
                    'name_ru' => $region['name_ru'],
                    'is_active' => true,
                    'sort_order' => $index,
                ],
            );
        }

        $tashkentCity = Region::query()->where('code', 'toshkent-shahri')->firstOrFail();

        $districts = [
            ['code' => 'bektemir', 'name_uz' => 'Bektemir', 'name_ru' => 'Бектемир'],
            ['code' => 'chilonzor', 'name_uz' => 'Chilonzor', 'name_ru' => 'Чиланзар'],
            ['code' => 'mirobod', 'name_uz' => 'Mirobod', 'name_ru' => 'Мирабад'],
            ['code' => 'mirzo-ulugbek', 'name_uz' => 'Mirzo Ulugʻbek', 'name_ru' => 'Мирзо-Улугбек'],
            ['code' => 'olmazor', 'name_uz' => 'Olmazor', 'name_ru' => 'Алмазар'],
            ['code' => 'sergeli', 'name_uz' => 'Sergeli', 'name_ru' => 'Сергели'],
            ['code' => 'shayxontohur', 'name_uz' => 'Shayxontohur', 'name_ru' => 'Шайхантахур'],
            ['code' => 'uchtepa', 'name_uz' => 'Uchtepa', 'name_ru' => 'Учтепа'],
            ['code' => 'yakkasaroy', 'name_uz' => 'Yakkasaroy', 'name_ru' => 'Яккасарай'],
            ['code' => 'yashnobod', 'name_uz' => 'Yashnobod', 'name_ru' => 'Яшнабад'],
            ['code' => 'yunusobod', 'name_uz' => 'Yunusobod', 'name_ru' => 'Юнусабад'],
            ['code' => 'yangihayot', 'name_uz' => 'Yangihayot', 'name_ru' => 'Янгихаёт'],
        ];

        foreach ($districts as $index => $district) {
            Region::updateOrCreate(
                ['code' => $district['code']],
                [
                    'parent_id' => $tashkentCity->id,
                    'name_uz' => $district['name_uz'],
                    'name_ru' => $district['name_ru'],
                    'is_active' => true,
                    'sort_order' => $index,
                ],
            );
        }
    }
}
