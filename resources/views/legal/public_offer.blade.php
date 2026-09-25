<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22mm 18mm 20mm; }
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 10.5px; color: #111; line-height: 1.5; }
        .brand { text-align: center; font-size: 13px; font-weight: bold; margin: 0 0 6px; }
        h1 { font-size: 13px; text-align: center; margin: 0 0 16px; }
        h2 { font-size: 11.5px; margin: 14px 0 4px; border-bottom: 1px solid #ddd; padding-bottom: 2px; page-break-after: avoid; }
        p { margin: 4px 0; text-align: justify; }
        .req { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .req td { padding: 3px 6px; border: 1px solid #ccc; vertical-align: top; }
        .req td.k { width: 28%; font-weight: bold; }
        .footer { margin-top: 18px; font-size: 8.5px; color: #777; text-align: center; }
    </style>
</head>
<body>
    <div class="brand">«PRB»</div>
    <h1>{{ $doc['title'] }}</h1>

    @foreach ($doc['sections'] as $section)
        <h2>{{ $section['title'] }}</h2>
        @foreach ($section['clauses'] as $clause)
            <p>@if ($clause['label'] !== '')<b>{{ $clause['label'] }}</b> @endif{{ $clause['text'] }}</p>
        @endforeach
    @endforeach

    <h2>{{ $doc['requisites_title'] }}</h2>
    <table class="req">
        @foreach ($doc['requisites'] as $row)
            <tr><td class="k">{{ $row['label'] }}</td><td>{{ $row['value'] }}</td></tr>
        @endforeach
    </table>

    <div class="footer">reklamabozor.uz · {{ $version }}</div>
</body>
</html>
