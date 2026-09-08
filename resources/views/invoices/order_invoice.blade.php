<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 11px; color: #111; line-height: 1.5; }
        h1 { font-size: 15px; text-align: center; margin: 0 0 2px; }
        .sub { text-align: center; color: #555; font-size: 10px; margin-bottom: 14px; }
        .meta { width: 100%; margin-bottom: 12px; font-size: 10px; color: #333; }
        h2 { font-size: 12px; margin: 12px 0 4px; border-bottom: 1px solid #ddd; padding-bottom: 2px; }
        p { margin: 4px 0; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 10px; }
        table.items th, table.items td { border: 1px solid #ccc; padding: 5px 6px; text-align: left; }
        table.items th { background: #f4f4f5; }
        table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
        .total-row td { font-weight: bold; background: #fafafa; }
        .parties { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .parties td { width: 50%; vertical-align: top; padding: 8px; border: 1px solid #ccc; font-size: 10px; }
        .parties .label { font-weight: bold; margin-bottom: 4px; display: block; }
        .row { margin: 1px 0; }
        .muted { color: #888; }
        .how { margin-top: 12px; padding: 8px; background: #f0f9ff; border: 1px solid #bae6fd; font-size: 10px; }
        .draft { margin-top: 10px; padding: 6px 8px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 9px; }
    </style>
</head>
@php
    $money = fn ($v) => number_format((float) $v, 0, '.', ' ').' so‘m';
    $val = fn ($v) => filled($v) ? $v : '—';
@endphp
<body>
    <h1>To‘lov hisobi (hisob-faktura)</h1>
    <div class="sub">
        «Reklama Bozor» platformasi · buyurtma #{{ $order->id }}
        @if ($contractNumber) · shartnoma № {{ $contractNumber }} @endif
    </div>

    <table class="meta">
        <tr>
            <td>Hisob №: <b>{{ $number }}</b></td>
            <td style="text-align:right">Sana: <b>{{ $issuedAt->format('d.m.Y') }}</b></td>
        </tr>
        <tr>
            <td>To‘lov usuli: <b>{{ $isCash ? 'naqd pul' : 'bank o‘tkazmasi' }}</b></td>
            <td style="text-align:right">
                @if ($dueAt) To‘lash muddati: <b>{{ $dueAt->format('d.m.Y') }}</b> @endif
            </td>
        </tr>
    </table>

    <h2>1. To‘lov tafsiloti</h2>
    <table class="items">
        <thead>
            <tr>
                <th style="width:26px">№</th>
                <th>Nomi</th>
                <th class="num">Soni</th>
                <th class="num">Narxi</th>
                <th class="num">Summa</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item['name'] }}</td>
                    <td class="num">{{ rtrim(rtrim((string) $item['quantity'], '0'), '.') }} {{ $item['unit'] }}</td>
                    <td class="num">{{ $money($item['unit_price']) }}</td>
                    <td class="num">{{ $money($item['line_total']) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="4" class="num">To‘lanadigan summa:</td>
                <td class="num">{{ $total }} so‘m</td>
            </tr>
        </tbody>
    </table>

    <h2>2. Tomonlar</h2>
    <table class="parties">
        <tr>
            <td>
                <span class="label">TO‘LOVCHI</span>
                @if (($payer['is_legal_entity'] ?? false) && ($payer['company_name'] ?? null))
                    <div class="row">{{ $payer['company_name'] }}</div>
                    @if ($payer['inn'] ?? null)<div class="row">STIR (INN): {{ $payer['inn'] }}</div>@endif
                    <div class="row muted">Vakil: {{ $val($payer['name'] ?? null) }}</div>
                @else
                    <div class="row">{{ $val($payer['name'] ?? null) }}</div>
                    <div class="row muted">Jismoniy shaxs</div>
                @endif
                @if ($payer['phone'] ?? null)<div class="row">Telefon: {{ $payer['phone'] }}</div>@endif
            </td>
            <td>
                <span class="label">QABUL QILUVCHI (OPERATOR)</span>
                <div class="row">{{ $val($platform['legal_name'] ?? $platform['name'] ?? null) }}</div>
                @if ($platform['inn'] ?? null)<div class="row">STIR (INN): {{ $platform['inn'] }}</div>@endif
                @if ($platform['oked'] ?? null)<div class="row">OKED: {{ $platform['oked'] }}</div>@endif
                @if ($platform['bank_account'] ?? null)<div class="row">H/r: {{ $platform['bank_account'] }}</div>@endif
                @if ($platform['bank_name'] ?? null)<div class="row">Bank: {{ $platform['bank_name'] }}</div>@endif
                @if ($platform['mfo'] ?? null)<div class="row">MFO: {{ $platform['mfo'] }}</div>@endif
                @if ($platform['address'] ?? null)<div class="row">Manzil: {{ $platform['address'] }}</div>@endif
                @if ($platform['phone'] ?? null)<div class="row">Telefon: {{ $platform['phone'] }}</div>@endif
            </td>
        </tr>
    </table>

    <div class="how">
        @if ($isCash)
            <b>Naqd to‘lov:</b> summani platforma kassasiga topshiring
            @if ($platform['cash_desk'] ?? null) ({{ $platform['cash_desk'] }}) @endif
            va ushbu hisob raqamini ({{ $number }}) ko‘rsating. To‘lov qabul qilingach operator uni
            tizimda tasdiqlaydi va buyurtma «to‘langan» holatiga o‘tadi.
        @else
            <b>Bank o‘tkazmasi:</b> to‘lovni yuqoridagi hisob raqamiga o‘tkazing.
            To‘lov maqsadi: «{{ $number }} — buyurtma #{{ $order->id }} uchun to‘lov{{ $contractNumber ? ', shartnoma № '.$contractNumber : '' }}».
            O‘tkazma kelib tushgach operator to‘lovni tizimda tasdiqlaydi.
        @endif
        <div style="margin-top:4px">Karta yoki online to‘lov uchun ilovadagi «Online to‘lash» tugmasidan foydalaning.</div>
    </div>

    <div class="draft">
        DRAFT — namunaviy shakl. Rekvizitlar va yakuniy huquqiy matn tasdiqlanishi shart.
        Hujjat: {{ $number }} · versiya: {{ \App\Services\Payment\InvoiceService::VERSION }}
    </div>
</body>
</html>
