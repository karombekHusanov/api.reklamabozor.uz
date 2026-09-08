<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    @include('acts._style')
</head>
@php
    $money = fn ($v) => number_format((float) $v, 0, '.', ' ').' so‘m';
    $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d.m.Y') : '—';
    $percent = rtrim(rtrim((string) $doc['commission_percent'], '0'), '.');
@endphp
<body>
    <h1>{{ $doc['title'] }}</h1>
    <div class="sub">{{ $doc['subtitle'] }}</div>

    <table class="meta">
        <tr>
            <td>Dalolatnoma №: <b>{{ $doc['number'] }}</b></td>
            <td style="text-align:right">Sana: <b>{{ $date($doc['date']) }}</b></td>
        </tr>
        <tr>
            <td>Shartnoma №: <b>{{ $doc['contract']['number'] ?? '—' }}</b> ({{ $date($doc['contract']['date'] ?? null) }})</td>
            <td style="text-align:right">Buyurtma: <b>#{{ $doc['order_id'] }}</b></td>
        </tr>
    </table>

    <h2>1. Ko‘rsatilgan xizmat</h2>
    <table class="items">
        <thead>
            <tr>
                <th style="width:24px">№</th>
                <th>Xizmat nomi</th>
                <th class="num">Shartnoma summasi</th>
                <th class="num">Stavka</th>
                <th class="num">Summa</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td>Marketplace vositachiligi va to‘lovlarni qabul qilish xizmati (buyurtma #{{ $doc['order_id'] }})</td>
                <td class="num">{{ $money($doc['deal_total']) }}</td>
                <td class="num">{{ $percent }}%</td>
                <td class="num">{{ $money($doc['total']) }}</td>
            </tr>
            <tr class="total-row">
                <td colspan="4">Jami xizmat haqi</td>
                <td class="num">{{ $money($doc['total']) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="words">
        Xizmat haqi: <b>{{ $doc['total_in_words'] }}</b><br>
        Ijrochiga o‘tkaziladigan summa: <b>{{ $money($doc['net_amount']) }}</b>
    </div>

    <h2>2. Tomonlarning tasdig‘i</h2>
    @foreach ($doc['clauses'] as $i => $clause)
        <p>2.{{ $i + 1 }}. {{ $clause }}</p>
    @endforeach

    <h2>3. Tomonlar rekvizitlari</h2>
    @include('acts._parties', [
        'leftLabel' => 'XIZMAT KO‘RSATUVCHI (OPERATOR)',
        'left' => $doc['provider'],
        'rightLabel' => 'BUYURTMACHI (IJROCHI-AGENTLIK)',
        'right' => $doc['payer'],
    ])

    <p class="muted" style="margin-top:10px; font-size:9px">
        Hujjat «Reklama Bozor» platformasida elektron shaklda shakllantirildi.
    </p>
</body>
</html>
