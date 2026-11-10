<?php

/**
 * Advertising placements (LED screens, billboards) shown on the mini app map.
 */
return [
    // Ordering screens from the map (POST /orders with `placements[]`). OFF while
    // the business model is being decided (2026-10-31): the map shows the
    // screens and their owners' public contacts, the client calls the owner.
    // Turning it on re-enables the Tender-without-gate path end to end.
    'ordering_enabled' => (bool) env('PLACEMENTS_ORDERING_ENABLED', false),

    // Category an order built from map placements lands in when the client did
    // not pick one. Looked up by (name_uz, type=agent); created by the
    // placements migration / CategorySeeder as a child of "Tashqi reklama".
    'default_category_name_uz' => env('PLACEMENTS_DEFAULT_CATEGORY', 'LED ekran'),
    'default_category_parent_name_uz' => 'Tashqi reklama',

    // Max placements one order may reference.
    'max_per_order' => (int) env('PLACEMENTS_MAX_PER_ORDER', 20),

    // Calculator (POST /placements/estimate): owners price a clip per month, the
    // client may book any number of days — one day = month price / month_days.
    'month_days' => (int) env('PLACEMENTS_MONTH_DAYS', 30),
    // Owners' price lists are net of VAT; the estimate shows it as its own line.
    'vat_percent' => (int) env('PLACEMENTS_VAT_PERCENT', 12),
    // Owners sell by the month: shorter bookings are not offered, so the
    // calculator never quotes a pro-rated price for less than this many days.
    'min_estimate_days' => (int) env('PLACEMENTS_MIN_ESTIMATE_DAYS', 30),
    'max_estimate_days' => 365,

    // Two imported screens closer than this share a `group_key` (same pole /
    // two faces) so the map can stack them instead of overlapping pins.
    'group_radius_m' => 30,
];
