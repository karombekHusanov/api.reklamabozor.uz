<?php

namespace Database\Seeders;

use App\Models\Advantage;
use Illuminate\Database\Seeder;

/**
 * Starter catalog of provider advantages. Admins refine it later via the
 * admin panel CRUD — re-running the seeder never duplicates rows.
 */
class AdvantageSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['icon' => 'timer', 'name_uz' => 'Tez bajarish', 'name_ru' => 'Быстрое выполнение', 'hint_uz' => 'Kelishilgan muddatdan oldin topshiramiz', 'hint_ru' => 'Сдаём раньше срока'],
            ['icon' => 'shield-check', 'name_uz' => 'Sifat kafolati', 'name_ru' => 'Гарантия качества', 'hint_uz' => 'Natija kafolatlanadi', 'hint_ru' => 'Результат гарантирован'],
            ['icon' => 'pen-tool', 'name_uz' => 'Bepul dizayn', 'name_ru' => 'Бесплатный дизайн', 'hint_uz' => 'Maket biz bilan bepul', 'hint_ru' => 'Макет бесплатно'],
            ['icon' => 'truck', 'name_uz' => 'Yetkazish va montaj', 'name_ru' => 'Доставка и монтаж', 'hint_uz' => "O'rnatishgacha to'liq xizmat", 'hint_ru' => 'Полный сервис до установки'],
            ['icon' => 'badge-percent', 'name_uz' => 'Hamyonbop narx', 'name_ru' => 'Доступные цены', 'hint_uz' => 'Bozordan arzon takliflar', 'hint_ru' => 'Дешевле рынка'],
            ['icon' => 'users', 'name_uz' => 'Tajribali jamoa', 'name_ru' => 'Опытная команда', 'hint_uz' => "Ko'p yillik mutaxassislar", 'hint_ru' => 'Специалисты с опытом'],
            ['icon' => 'map-pin', 'name_uz' => 'Keng qamrov', 'name_ru' => 'Широкий охват', 'hint_uz' => 'Butun respublika bo‘ylab', 'hint_ru' => 'По всей республике'],
            ['icon' => 'file-check', 'name_uz' => 'Shartnoma bilan', 'name_ru' => 'Работа по договору', 'hint_uz' => 'Rasmiy hujjatlar bilan ishlaymiz', 'hint_ru' => 'Официальные документы'],
            ['icon' => 'headphones', 'name_uz' => '24/7 aloqa', 'name_ru' => 'Связь 24/7', 'hint_uz' => 'Har doim aloqadamiz', 'hint_ru' => 'Всегда на связи'],
            ['icon' => 'sparkles', 'name_uz' => 'Kreativ yechimlar', 'name_ru' => 'Креативные решения', 'hint_uz' => 'Nostandart g‘oyalar', 'hint_ru' => 'Нестандартные идеи'],
            ['icon' => 'calendar-check', 'name_uz' => 'Muddatga rioya', 'name_ru' => 'Соблюдение сроков', 'hint_uz' => 'Kechikish yo‘q', 'hint_ru' => 'Без задержек'],
            ['icon' => 'refresh-ccw', 'name_uz' => 'Bepul tuzatishlar', 'name_ru' => 'Бесплатные правки', 'hint_uz' => 'Natija yoqquncha tuzatamiz', 'hint_ru' => 'Правим до результата'],
        ];

        foreach ($items as $index => $item) {
            Advantage::query()->updateOrCreate(
                ['icon' => $item['icon'], 'name_uz' => $item['name_uz']],
                [...$item, 'is_active' => true, 'sort_order' => $index],
            );
        }
    }
}
