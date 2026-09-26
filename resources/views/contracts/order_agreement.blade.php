<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 10.5px; color: #111; line-height: 1.5; }
        .brand { text-align: center; font-weight: bold; font-size: 12px; margin-bottom: 6px; }
        h1 { font-size: 13px; text-align: center; margin: 0 0 2px; }
        .sub { text-align: center; color: #444; font-size: 10px; margin-bottom: 12px; }
        .meta { width: 100%; margin-bottom: 10px; font-size: 10px; }
        h2 { font-size: 11px; margin: 12px 0 4px; border-bottom: 1px solid #ddd; padding-bottom: 2px; }
        p { margin: 3px 0; text-align: justify; }
        table.details { width: 100%; border-collapse: collapse; margin: 6px 0; font-size: 10px; }
        table.details td { border: 1px solid #ccc; padding: 4px 6px; vertical-align: top; }
        table.details td.label { width: 32%; font-weight: bold; background: #f7f7f8; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 9.5px; }
        table.items th, table.items td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        table.items th { background: #f4f4f5; }
        table.items td.num, table.items th.num { text-align: right; white-space: nowrap; }
        .caption { font-size: 10px; font-weight: bold; margin-top: 8px; }
        .total-row td { font-weight: bold; background: #fafafa; }
        .parties { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .parties td { width: 33%; vertical-align: top; padding: 7px; border: 1px solid #ccc; font-size: 9.5px; }
        .parties .label { font-weight: bold; display: block; }
        .parties .name { font-weight: bold; margin: 2px 0 4px; }
        .row { margin: 1px 0; }
        .sign { margin-top: 6px; padding-top: 14px; font-size: 9.5px; }
        .accepted { margin-top: 12px; padding: 6px 8px; background: #f0fdf4; border: 1px solid #bbf7d0; font-size: 9px; color: #166534; }
        .draft { margin-top: 10px; text-align: center; color: #888; font-size: 9px; }
    </style>
</head>
@php
    $money = fn ($v) => number_format((float) $v, 0, '.', ' ').' сўм';
@endphp
<body>
    <div class="brand">«PRB»</div>
    <h1>{{ $doc['title'] }}</h1>
    <div class="sub">{{ $doc['subtitle'] }}</div>

    <table class="meta">
        <tr>
            <td>{{ $doc['city'] ?? 'Тошкент ш.' }}</td>
            <td style="text-align:center">Шартнома № <b>{{ $doc['number'] }}</b></td>
            <td style="text-align:right"><b>{{ \Illuminate\Support\Carbon::parse($doc['generated_at'])->format('d.m.Y') }}</b> й.</td>
        </tr>
    </table>

    @foreach (explode("\n", $doc['intro']) as $line)
        <p>{{ $line }}</p>
    @endforeach

    @foreach ($doc['sections'] as $section)
        <h2>{{ $section['heading'] }}</h2>

        @foreach ($section['paragraphs'] as $paragraph)
            <p>{{ $paragraph }}</p>
        @endforeach

        @if ($section['type'] === 'items')
            @if (!empty($section['rows']))
                <table class="details">
                    @foreach ($section['rows'] as $row)
                        <tr>
                            <td class="label">{{ $row['label'] }}</td>
                            <td>{{ $row['value'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif

            <div class="caption">Харажатлар калькуляцияси</div>
            <table class="items">
                <thead>
                    <tr>
                        <th style="width:22px">№</th>
                        <th>Номи</th>
                        <th class="num">Сони</th>
                        <th class="num">Нархи</th>
                        <th class="num">Сумма</th>
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
                        <td colspan="4" class="num">Жами:</td>
                        <td class="num">{{ $money($doc['total']) }}</td>
                    </tr>
                </tbody>
            </table>
        @endif

        @if ($section['type'] === 'parties' && !empty($section['parties']))
            <table class="parties">
                <tr>
                    @foreach ($section['parties'] as $party)
                        <td>
                            <span class="label">{{ $party['label'] }}:</span>
                            <div class="name">{{ $party['name'] }}</div>
                            @foreach ($party['rows'] as $row)
                                <div class="row">{{ $row['label'] }}: {{ $row['value'] }}</div>
                            @endforeach
                            <div class="sign">Имзо (электрон акцепт): __________ {{ $party['key'] !== 'client' ? 'М.Ў.' : '' }}</div>
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif
    @endforeach

    @if (!empty($acceptances))
        <div class="accepted">
            Электрон акцепт (платформада тасдиқлаш):
            @foreach ($acceptances as $entry)
                {{ $entry['label'] }} — {{ $entry['name'] }}, {{ $entry['accepted_at'] }}@if (!$loop->last);@endif
            @endforeach
        </div>
    @endif

    <div class="draft">
        — {{ $doc['draft_note'] ?? 'лойиҳа матни' }} —<br>
        Ҳужжат: {{ $doc['number'] }} · матн версияси: {{ $doc['version'] }} · hash: {{ substr($doc['hash'] ?? '', 0, 16) }}
    </div>
</body>
</html>
