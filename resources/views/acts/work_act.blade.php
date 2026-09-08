<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    @include('acts._style')
</head>
@php
    $money = fn ($v) => number_format((float) $v, 0, '.', ' ').' so‘m';
    $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d.m.Y') : '—';
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
        <tr>
            <td colspan="2">
                Ish davri: {{ $date($doc['period']['from'] ?? null) }} — {{ $date($doc['period']['to'] ?? null) }}
            </td>
        </tr>
    </table>

    <h2>1. Bajarilgan ishlar</h2>
    <table class="items">
        <thead>
            <tr>
                <th style="width:24px">№</th>
                <th>Nomi</th>
                <th class="num">Birlik</th>
                <th class="num">Soni</th>
                <th class="num">Narxi</th>
                <th class="num">Summa</th>
                <th class="num">MXIK</th>
                <th class="num">QQS</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($doc['items'] as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item['name'] }}</td>
                    <td class="num">{{ $item['unit'] ?? '—' }}</td>
                    <td class="num">{{ rtrim(rtrim((string) $item['quantity'], '0'), '.') ?: '1' }}</td>
                    <td class="num">{{ $money($item['unit_price']) }}</td>
                    <td class="num">{{ $money($item['line_total']) }}</td>
                    <td class="num">{{ $item['mxik_code'] ?? '—' }}</td>
                    <td class="num">{{ $item['vat_rate'] !== null ? rtrim(rtrim((string) $item['vat_rate'], '0'), '.').'%' : '—' }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="5">Jami</td>
                <td class="num">{{ $money($doc['total']) }}</td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>

    <div class="words">
        Jami summa: <b>{{ $doc['total_in_words'] }}</b><br>
        {{ $doc['vat_note'] }}
    </div>

    <h2>2. Tomonlarning tasdig‘i</h2>
    @foreach ($doc['clauses'] as $i => $clause)
        <p>2.{{ $i + 1 }}. {{ $clause }}</p>
    @endforeach

    <h2>3. Tomonlar rekvizitlari</h2>
    @include('acts._parties', [
        'leftLabel' => 'IJROCHI',
        'left' => $doc['executor'],
        'rightLabel' => 'BUYURTMACHI',
        'right' => $doc['customer'],
    ])

    <p class="muted" style="margin-top:10px; font-size:9px">
        Operator: {{ $doc['operator']['legal_name'] ?? $doc['operator']['name'] ?? '—' }}
        @if (! empty($doc['operator']['inn'])) · INN {{ $doc['operator']['inn'] }} @endif
        — marketplace va to‘lov operatori sifatida ishtirok etadi.
        Hujjat «Reklama Bozor» platformasida elektron shaklda shakllantirildi.
    </p>
</body>
</html>
