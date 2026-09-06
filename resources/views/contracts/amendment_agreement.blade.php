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
        .cols { width: 100%; border-collapse: collapse; }
        .cols td { width: 50%; vertical-align: top; padding: 0 6px; }
        .sign { margin-top: 6px; padding-top: 18px; border-top: 1px dashed #999; font-size: 10px; }
        .draft { margin-top: 18px; padding: 6px 8px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 9px; }
        .flag { margin-top: 8px; padding: 6px 8px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-size: 9px; }
    </style>
</head>
@php
    $money = fn ($v) => number_format((float) $v, 0, '.', ' ').' so‘m';
    $qty = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
    $renderItems = function ($items) use ($money, $qty) {
        $rows = '';
        foreach ($items as $i => $item) {
            $rows .= '<tr><td>'.($i + 1).'</td><td>'.e($item['name']).'</td>'
                .'<td class="num">'.e($qty($item['quantity'])).' '.e($item['unit']).'</td>'
                .'<td class="num">'.$money($item['unit_price']).'</td>'
                .'<td class="num">'.$money($item['line_total']).'</td></tr>';
        }
        return $rows;
    };
@endphp
<body>
    <h1>Qo'shimcha kelishuv</h1>
    <div class="sub">«{{ $contractNumber }}» xizmat shartnomasiga · «Reklama Bozor» platformasi orqali</div>

    <table class="meta">
        <tr>
            <td>Qo'shimcha kelishuv №: <b>{{ $number }}</b></td>
            <td style="text-align:right">Sana: <b>{{ $generatedAt->format('d.m.Y') }}</b></td>
        </tr>
    </table>

    <p>
        Tomonlar — <b>Ijrochi</b> ({{ $agentName ?: '—' }}) va <b>Buyurtmachi</b> ({{ $clientName ?: '—' }}) —
        yuqoridagi shartnoma shartlariga o'zgartirish kiritish to'g'risida kelishib oldilar.
        Tashabbuskor: <b>{{ $initiatorLabel }}</b>.
    </p>
    @if ($reason)
        <p>Asos / izoh: {{ $reason }}</p>
    @endif

    <h2>1. O'zgartirilgan xizmatlar va narxi</h2>
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
            {!! $renderItems($after['items']) !!}
            <tr class="total-row">
                <td colspan="4" class="num">Yangi jami:</td>
                <td class="num">{{ $money($after['total']) }}</td>
            </tr>
        </tbody>
    </table>

    <h2>2. O'zgarishlar xulosasi</h2>
    <p>2.1. Oldingi summa: <b>{{ $money($before['total']) }}</b> → Yangi summa: <b>{{ $money($after['total']) }}</b>.</p>
    @if ((float) $extraAmount > 0)
        <p>2.2. Qo'shimcha to'lov: <b>{{ $money($extraAmount) }}</b> — Buyurtmachi tomonidan platforma orqali to'lanadi.</p>
    @elseif ((float) $extraAmount < 0)
        <p>2.2. Summa <b>{{ $money(abs((float) $extraAmount)) }}</b> ga kamaydi.</p>
    @endif
    @if ($beforeDeadline !== $afterDeadline)
        <p>2.3. Bajarilish muddati: <b>{{ $beforeDeadline ?: '—' }}</b> → <b>{{ $afterDeadline ?: '—' }}</b>.</p>
    @endif

    <h2>3. Tasdiqlash</h2>
    <table class="cols">
        <tr>
            <td>
                <div><b>IJROCHI</b></div>
                <div>{{ $agentName ?: '—' }}</div>
                <div class="sign">Imzo: ______________</div>
            </td>
            <td>
                <div><b>BUYURTMACHI</b></div>
                <div>{{ $clientName ?: '—' }}</div>
                <div class="sign">Imzo: ______________</div>
            </td>
        </tr>
    </table>

    @if ($requiresFormalDoc)
        <div class="flag">
            Diqqat: ushbu o'zgarish muddatga ta'sir qiladi yoki dastlabki tarifnoma doirasidan chiqadi.
            Elektron tasdiq bilan bir qatorda rasmiy (imzolangan/muhrlangan) shaklda ham
            rasmiylashtirilishi tavsiya etiladi.
        </div>
    @endif

    <div class="draft">
        DRAFT — namunaviy matn. Yakuniy huquqiy matn yurist tomonidan tasdiqlanishi shart.
        Hujjat identifikatori: {{ $number }}.
    </div>
</body>
</html>
