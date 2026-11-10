<?php

/**
 * Trans Group metro advertising price list, 2026 (commercial offer PDF).
 * Prices: so'm per month, net of VAT. Digital formats are priced per clip
 * length (5 s / 10 s); static ones have a single price, a 3-month minimum and
 * printing + mounting billed separately. `package` = sold only as the whole set.
 * Line keys: chilonzor, ozbekiston, yunusobod, halqa.
 */
$digital = [5 => 3_500_000, 10 => 4_800_000];

return [
    'owner' => ['name' => 'Trans Group', 'phones' => ['+998 97 157 32 31'], 'website' => 'reklamavmetro.uz'],
    'formats' => [
        ['code' => 'metro_wagon_monitors', 'medium' => 'digital', 'package' => true, 'name_uz' => 'Vagon ichidagi monitorlar', 'name_ru' => 'Мониторы в новых вагонах',
            'specs' => ['size' => '25×16 sm', 'resolution' => '1280×720', 'hours' => '07:00–23:00', 'note' => '1 poyezdda 24 ta'],
            'lines' => ['ozbekiston' => 246, 'chilonzor' => 246, 'halqa' => 246, 'yunusobod' => 246], 'reach' => 100_000, 'rates' => [5 => 20_000_000, 10 => 28_500_000]],
        ['code' => 'metro_led_bench', 'medium' => 'digital', 'name_uz' => 'Skameykali LED monitor', 'name_ru' => 'LED-мониторы со скамьёй',
            'specs' => ['size' => '176×224 sm', 'resolution' => '768×576 / 898×512', 'hours' => '07:00–23:00'],
            'lines' => ['ozbekiston' => 4, 'chilonzor' => 4, 'halqa' => 1], 'reach' => 100_000, 'rates' => $digital],
        ['code' => 'metro_led_stand_360', 'medium' => 'digital', 'name_uz' => 'LED-stoyka 360', 'name_ru' => 'LED-стойки 360',
            'specs' => ['resolution' => '512×320', 'hours' => '07:00–23:00'],
            'lines' => ['halqa' => 6], 'reach' => 100_000, 'rates' => $digital],
        ['code' => 'metro_led_entrance', 'medium' => 'digital', 'name_uz' => 'Kirish ustidagi LED ekran', 'name_ru' => 'LED-экран над входом',
            'specs' => ['resolution' => '1152×352', 'hours' => '07:00–23:00'],
            'lines' => ['ozbekiston' => 2, 'yunusobod' => 8], 'reach' => 850_000, 'rates' => $digital],
        ['code' => 'metro_led_exit', 'medium' => 'digital', 'name_uz' => 'Chiqish ustidagi LED ekran', 'name_ru' => 'LED-экран над выходом',
            'specs' => ['resolution' => '720×200 (Chinor: 960×600)', 'hours' => '07:00–23:00'],
            'lines' => ['ozbekiston' => 1, 'chilonzor' => 3, 'halqa' => 9], 'reach' => 100_000, 'rates' => $digital],
        ['code' => 'metro_led_track_panel', 'medium' => 'digital', 'name_uz' => "Yo'l devoridagi LED panel", 'name_ru' => 'LED-панели на путевых указателях',
            'specs' => ['resolution' => '880×600 (Yunusobod) / 864×192 (Halqa)', 'hours' => '07:00–23:00'],
            'lines' => ['yunusobod' => 6, 'chilonzor' => 8, 'halqa' => 12], 'reach' => 1_000_000, 'rates' => $digital],
        ['code' => 'metro_led_hanging_monitor', 'medium' => 'digital', 'name_uz' => 'Osma LED monitor', 'name_ru' => 'LED-мониторы подвесные',
            'specs' => ['size' => '55"', 'resolution' => '1920×1080', 'hours' => '07:00–23:00'],
            'lines' => ['ozbekiston' => 8, 'yunusobod' => 6, 'chilonzor' => 6], 'reach' => 800_000, 'rates' => $digital],

        ['code' => 'metro_track_panel', 'medium' => 'static', 'min_months' => 3, 'name_uz' => "Yo'l devoridagi panel", 'name_ru' => 'Панели на путевых указателях',
            'specs' => ['size' => '244×122 sm', 'material' => 'Orakal, qora taglik'],
            'lines' => ['ozbekiston' => 35, 'yunusobod' => 21, 'chilonzor' => 59], 'reach' => 1_000_000, 'rates' => [0 => 2_200_000]],
        ['code' => 'metro_wagon_posters', 'medium' => 'static', 'package' => true, 'min_months' => 3, 'name_uz' => 'Vagon ichidagi posterlar', 'name_ru' => 'Постеры в вагонах',
            'specs' => ['size' => '51×57 sm', 'material' => 'Banner'],
            'lines' => ['ozbekiston' => 53, 'yunusobod' => 53, 'chilonzor' => 54], 'reach' => 1_000_000, 'rates' => [0 => 45_000_000]],
        ['code' => 'metro_lightbox_cashier', 'medium' => 'static', 'package' => true, 'name_uz' => 'Kassa yonidagi Light Box', 'name_ru' => 'Light Box возле касс',
            'specs' => ['size' => '100×150 / 90×120 / 70×120 sm', 'material' => 'Bekprint'],
            'lines' => ['ozbekiston' => 8, 'yunusobod' => 5, 'chilonzor' => 11], 'reach' => 850_000, 'rates' => [0 => 22_000_000]],
        ['code' => 'metro_lightbox_hanging', 'medium' => 'static', 'min_months' => 3, 'name_uz' => 'Osma Light Box', 'name_ru' => 'Подвесной Light Box',
            'specs' => ['size' => '100×60 sm', 'material' => 'Bekprint'],
            'lines' => ['ozbekiston' => 72, 'yunusobod' => 56, 'chilonzor' => 41], 'reach' => 1_000_000, 'rates' => [0 => 650_000]],
        ['code' => 'metro_static_descent', 'medium' => 'static', 'min_months' => 3, 'name_uz' => 'Stansiyaga tushishdagi banner', 'name_ru' => 'Статичные конструкции при спуске на станцию',
            'specs' => ['size' => '400×200 / 500×200 sm', 'material' => 'Banner'],
            'lines' => ['yunusobod' => 4, 'chilonzor' => 8], 'reach' => 1_000_000, 'rates' => [0 => 4_400_000]],
        ['code' => 'metro_lightbox_passage_medium', 'medium' => 'static', 'min_months' => 3, 'name_uz' => "O'tish yo'lidagi Light Box (o'rta)", 'name_ru' => 'Light Box в переходах среднего размера',
            'specs' => ['size' => '120×180 sm', 'material' => 'Bekprint'],
            'lines' => ['yunusobod' => 2, 'chilonzor' => 4], 'reach' => 1_000_000, 'rates' => [0 => 2_600_000]],
        ['code' => 'metro_lightbox_passage_large', 'medium' => 'static', 'min_months' => 3, 'name_uz' => "O'tish yo'lidagi Light Box (katta)", 'name_ru' => 'Light Box в переходах большего размера',
            'specs' => ['size' => '500×200 sm', 'material' => "Yorug'lik o'tkazuvchi banner"],
            'lines' => ['ozbekiston' => 3, 'chilonzor' => 3], 'reach' => 850_000, 'rates' => [0 => 5_200_000]],
        ['code' => 'metro_banner_passage_large', 'medium' => 'static', 'min_months' => 3, 'name_uz' => "O'tish yo'lidagi banner (katta)", 'name_ru' => 'Баннерные конструкции в переходах большего размера',
            'specs' => ['size' => '400×200 / 550×200 sm', 'material' => 'Banner'],
            'lines' => ['yunusobod' => 1, 'chilonzor' => 2], 'reach' => 1_000_000, 'rates' => [0 => 4_200_000]],
    ],
];
