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
        .parties td { width: 33%; vertical-align: top; padding: 8px; border: 1px solid #ccc; font-size: 10px; }
        .parties .label { font-weight: bold; margin-bottom: 4px; display: block; }
        .row { margin: 1px 0; }
        .sign { margin-top: 6px; padding-top: 18px; border-top: 1px dashed #999; font-size: 10px; }
        .muted { color: #888; }
        .accepted { margin-top: 12px; padding: 6px 8px; background: #f0fdf4; border: 1px solid #bbf7d0; font-size: 9px; color: #166534; }
        .draft { margin-top: 10px; padding: 6px 8px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 9px; }
    </style>
</head>
@php
    $money = fn ($v) => number_format((float) $v, 0, '.', ' ').' so‘m';
@endphp
<body>
    <h1>{{ $doc['title'] }}</h1>
    <div class="sub">{{ $doc['subtitle'] }}</div>

    <table class="meta">
        <tr>
            <td>Shartnoma №: <b>{{ $doc['number'] }}</b></td>
            <td style="text-align:right">Sana: <b>{{ \Illuminate\Support\Carbon::parse($doc['generated_at'])->format('d.m.Y') }}</b></td>
        </tr>
    </table>

    <p>{{ $doc['intro'] }}</p>

    @foreach ($doc['sections'] as $section)
        <h2>{{ $section['heading'] }}</h2>

        @foreach ($section['paragraphs'] as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach

        @if ($section['type'] === 'items')
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
                    @foreach ($doc['items'] as $i => $item)
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
                        <td class="num">{{ $money($doc['total']) }}</td>
                    </tr>
                </tbody>
            </table>
        @endif

        @if ($section['type'] === 'parties')
            <table class="parties">
                <tr>
                    <td>
                        <span class="label">IJROCHI</span>
                        <div class="row">{{ $doc['agent']['company_name'] ?: '—' }}</div>
                        @if ($doc['agent']['legal_form'])<div class="row">Shakl: {{ $doc['agent']['legal_form'] }}</div>@endif
                        @if ($doc['agent']['director_name'])<div class="row">Rahbar: {{ $doc['agent']['director_name'] }}</div>@endif
                        @if ($doc['agent']['inn'])<div class="row">STIR (INN): {{ $doc['agent']['inn'] }}</div>@endif
                        @if ($doc['agent']['phone'])<div class="row">Telefon: {{ $doc['agent']['phone'] }}</div>@endif
                        @if ($doc['agent']['address'])<div class="row">Manzil: {{ $doc['agent']['address'] }}</div>@endif
                        @if ($doc['agent']['bank_account'])<div class="row">H/r: {{ $doc['agent']['bank_account'] }}</div>@endif
                        @if ($doc['agent']['bank_name'])<div class="row">Bank: {{ $doc['agent']['bank_name'] }}</div>@endif
                        @if ($doc['agent']['mfo'])<div class="row">MFO: {{ $doc['agent']['mfo'] }}</div>@endif
                        <div class="sign">Aksept (ilovada): ______________</div>
                    </td>
                    <td>
                        <span class="label">BUYURTMACHI</span>
                        @if ($doc['client']['is_legal_entity'] && $doc['client']['company_name'])
                            <div class="row">{{ $doc['client']['company_name'] }}</div>
                            @if ($doc['client']['inn'])<div class="row">STIR (INN): {{ $doc['client']['inn'] }}</div>@endif
                            <div class="row muted">Vakil: {{ $doc['client']['name'] ?: '—' }}</div>
                        @else
                            <div class="row">{{ $doc['client']['name'] ?: '—' }}</div>
                            <div class="row muted">Jismoniy shaxs</div>
                        @endif
                        @if ($doc['client']['phone'])<div class="row">Telefon: {{ $doc['client']['phone'] }}</div>@endif
                        <div class="sign">Aksept (ilovada): ______________</div>
                    </td>
                    <td>
                        <span class="label">OPERATOR</span>
                        <div class="row">{{ $doc['platform']['legal_name'] ?: $doc['platform']['name'] }}</div>
                        <div class="row muted">{{ $doc['platform']['role'] }}</div>
                        @if ($doc['platform']['inn'])<div class="row">STIR (INN): {{ $doc['platform']['inn'] }}</div>@endif
                        @if ($doc['platform']['address'])<div class="row">Manzil: {{ $doc['platform']['address'] }}</div>@endif
                        @if ($doc['platform']['phone'])<div class="row">Telefon: {{ $doc['platform']['phone'] }}</div>@endif
                        @if ($doc['platform']['email'])<div class="row">Email: {{ $doc['platform']['email'] }}</div>@endif
                        @if ($doc['platform']['website'])<div class="row">{{ $doc['platform']['website'] }}</div>@endif
                        <div class="sign">Komissiya: {{ $doc['platform']['commission_percent'] }}%</div>
                    </td>
                </tr>
            </table>
        @endif
    @endforeach

    @if (!empty($acceptances))
        <div class="accepted">
            Elektron aksept (click-wrap):
            @foreach ($acceptances as $entry)
                {{ $entry['label'] }} — {{ $entry['name'] }}, {{ $entry['accepted_at'] }}@if (!$loop->last);@endif
            @endforeach
        </div>
    @endif

    <div class="draft">
        DRAFT — namunaviy matn. Yakuniy huquqiy matn yurist tomonidan tasdiqlanishi shart.
        Hujjat identifikatori: {{ $doc['number'] }} · matn versiyasi: {{ $doc['version'] }} · hash: {{ substr($doc['hash'] ?? '', 0, 16) }}
    </div>
</body>
</html>
