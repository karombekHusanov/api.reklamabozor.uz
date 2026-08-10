<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 11px; color: #111; line-height: 1.5; }
        h1 { font-size: 15px; text-align: center; margin: 0 0 2px; }
        .sub { text-align: center; color: #555; font-size: 10px; margin-bottom: 16px; }
        .meta { width: 100%; margin-bottom: 14px; font-size: 10px; color: #333; }
        h2 { font-size: 12px; margin: 14px 0 4px; border-bottom: 1px solid #ddd; padding-bottom: 2px; }
        p { margin: 4px 0; text-align: justify; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 10px; }
        table.items th, table.items td { border: 1px solid #ccc; padding: 5px 6px; text-align: left; }
        table.items th { background: #f4f4f5; }
        table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
        .total-row td { font-weight: bold; background: #fafafa; }
        .parties { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .parties td { width: 50%; vertical-align: top; padding: 8px; border: 1px solid #ccc; font-size: 10px; }
        .parties .label { font-weight: bold; margin-bottom: 4px; display: block; }
        .row { margin: 1px 0; }
        .sign { margin-top: 6px; padding-top: 18px; border-top: 1px dashed #999; font-size: 10px; }
        .muted { color: #888; }
        .draft { margin-top: 18px; padding: 6px 8px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 9px; }
    </style>
</head>
@php
    $money = fn ($v) => number_format((float) $v, 0, '.', ' ').' so‘m';
@endphp
<body>
    <h1>Xizmat ko'rsatish shartnomasi</h1>
    <div class="sub">Buyurtmachi (mijoz) ↔ Ijrochi (agentlik) · «Reklama Bozor» platformasi orqali</div>

    <table class="meta">
        <tr>
            <td>Shartnoma №: <b>{{ $number }}</b></td>
            <td style="text-align:right">Sana: <b>{{ $generatedAt->format('d.m.Y') }}</b></td>
        </tr>
    </table>

    <p>
        Bir tomondan <b>Ijrochi</b> — {{ $agent['company_name'] ?: '—' }}, ikkinchi tomondan
        <b>Buyurtmachi</b> — {{ $client['is_legal_entity'] ? ($client['company_name'] ?: $client['name']) : $client['name'] }},
        birgalikda Tomonlar deb atalib, ushbu shartnomani tuzdilar. To'lovlar «Reklama Bozor»
        platformasi orqali (to'lov operatori sifatida) amalga oshiriladi.
    </p>

    <h2>1. Shartnoma predmeti</h2>
    <p>1.1. Ijrochi Buyurtmachiga quyidagi reklama/poligrafiya xizmatlarini ko'rsatadi, Buyurtmachi
        esa ularni qabul qilib, kelishilgan narxni to'laydi.</p>
    @if ($order->description)
        <p>1.2. Buyurtma tavsifi: {{ $order->description }}</p>
    @endif

    <h2>2. Xizmatlar va narxi</h2>
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
                    <td class="num">{{ rtrim(rtrim($item['quantity'], '0'), '.') }} {{ $item['unit'] }}</td>
                    <td class="num">{{ $money($item['unit_price']) }}</td>
                    <td class="num">{{ $money($item['line_total']) }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="4" class="num">Jami:</td>
                <td class="num">{{ $money($total) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>3. To'lov va muddat</h2>
    <p>3.1. Xizmatlar umumiy qiymati: <b>{{ $money($total) }}</b>.</p>
    <p>3.2. To'lov «Reklama Bozor» platformasi orqali amalga oshiriladi; platforma to'lovni yuritadi
        va Tomonlar o'rtasida vositachilik qiladi.</p>
    @if ($deadlineLabel)
        <p>3.3. Bajarilish muddati: <b>{{ $deadlineLabel }}</b>.</p>
    @endif

    <h2>4. Tomonlarning javobgarligi</h2>
    <p>4.1. Ijrochi xizmatlarni sifatli va o'z vaqtida bajarish uchun javobgardir.</p>
    <p>4.2. Buyurtmachi qabul qilingan xizmatlar uchun to'lovni o'z vaqtida amalga oshiradi.</p>
    <p>4.3. Reklama mazmuni (matn, tasvir) uchun javobgarlik Buyurtmachi zimmasida.</p>

    <h2>5. Tomonlarning rekvizitlari</h2>
    <table class="parties">
        <tr>
            <td>
                <span class="label">IJROCHI</span>
                <div class="row">{{ $agent['company_name'] ?: '—' }}</div>
                @if ($agent['legal_form'])<div class="row">Shakl: {{ $agent['legal_form'] }}</div>@endif
                @if ($agent['director_name'])<div class="row">Rahbar: {{ $agent['director_name'] }}</div>@endif
                @if ($agent['inn'])<div class="row">STIR (INN): {{ $agent['inn'] }}</div>@endif
                @if ($agent['phone'])<div class="row">Telefon: {{ $agent['phone'] }}</div>@endif
                @if ($agent['address'])<div class="row">Manzil: {{ $agent['address'] }}</div>@endif
                @if ($agent['bank_account'])<div class="row">H/r: {{ $agent['bank_account'] }}</div>@endif
                @if ($agent['bank_name'])<div class="row">Bank: {{ $agent['bank_name'] }}</div>@endif
                @if ($agent['mfo'])<div class="row">MFO: {{ $agent['mfo'] }}</div>@endif
                <div class="sign">Imzo: ______________</div>
            </td>
            <td>
                <span class="label">BUYURTMACHI</span>
                @if ($client['is_legal_entity'] && $client['company_name'])
                    <div class="row">{{ $client['company_name'] }}</div>
                    @if ($client['inn'])<div class="row">STIR (INN): {{ $client['inn'] }}</div>@endif
                    <div class="row muted">Vakil: {{ $client['name'] ?: '—' }}</div>
                @else
                    <div class="row">{{ $client['name'] ?: '—' }}</div>
                    <div class="row muted">Jismoniy shaxs</div>
                @endif
                @if ($client['phone'])<div class="row">Telefon: {{ $client['phone'] }}</div>@endif
                <div class="sign">Imzo: ______________</div>
            </td>
        </tr>
    </table>

    <div class="draft">
        DRAFT — namunaviy matn. Yakuniy huquqiy matn yurist tomonidan tasdiqlanishi shart.
        Hujjat identifikatori: {{ $number }}.
    </div>
</body>
</html>
