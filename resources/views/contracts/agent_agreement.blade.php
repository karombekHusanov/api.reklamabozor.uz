<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 11px; color: #111; line-height: 1.5; }
        h1 { font-size: 15px; text-align: center; margin: 0 0 2px; }
        .sub { text-align: center; color: #555; font-size: 10px; margin-bottom: 16px; }
        .meta { width: 100%; margin-bottom: 14px; }
        .meta td { font-size: 10px; color: #333; }
        h2 { font-size: 12px; margin: 14px 0 4px; border-bottom: 1px solid #ddd; padding-bottom: 2px; }
        p { margin: 4px 0; text-align: justify; }
        .parties { width: 100%; border-collapse: collapse; margin-top: 6px; }
        .parties td { width: 50%; vertical-align: top; padding: 8px; border: 1px solid #ccc; font-size: 10px; }
        .parties .label { font-weight: bold; margin-bottom: 4px; display: block; }
        .row { margin: 1px 0; }
        .sign { margin-top: 6px; padding-top: 18px; border-top: 1px dashed #999; font-size: 10px; }
        .draft { margin-top: 18px; padding: 6px 8px; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 9px; }
        .muted { color: #888; }
    </style>
</head>
<body>
    <h1>Reklama Bozor — Agentlik hamkorlik shartnomasi</h1>
    <div class="sub">Platforma ↔ Agent (provayder) o'rtasidagi ommaviy shartnoma</div>

    <table class="meta">
        <tr>
            <td>Shartnoma №: <b>RB-A-{{ $profile->id }}-{{ $version }}</b></td>
            <td style="text-align:right">Sana: <b>{{ $generatedAt->format('d.m.Y') }}</b></td>
        </tr>
    </table>

    <p>
        Bir tomondan reklama xizmatlari marketplace platformasi <b>«Reklama Bozor»</b> (bundan buyon
        — <b>Platforma</b>), ikkinchi tomondan quyida rekvizitlari ko'rsatilgan
        <b>{{ $profile->company_name ?: $profile->director_name }}</b> (bundan buyon — <b>Agent</b>),
        birgalikda Tomonlar deb atalib, ushbu shartnomani quyidagilar to'g'risida tuzdilar.
    </p>

    <h2>1. Shartnoma predmeti</h2>
    <p>1.1. Platforma Agentga o'z onlayn platformasi orqali mijozlar bilan bog'lanish, buyurtmalarga
        taklif (narxlar ro'yxati) yuborish va reklama xizmatlarini ko'rsatish imkoniyatini beradi.</p>
    <p>1.2. Agent mijozlarga reklama/poligrafiya xizmatlarini mustaqil ravishda, o'z nomidan va o'z
        javobgarligi ostida ko'rsatadi. Platforma xizmat sifati uchun tomon hisoblanmaydi — u vositachi
        va to'lovlarni yurituvchi operator sifatida ishtirok etadi.</p>

    <h2>2. Komissiya va hisob-kitob</h2>
    <p>2.1. Platforma har bir yakunlangan buyurtmadan <b>{{ rtrim(rtrim(number_format($commissionPercent, 2), '0'), '.') }}%</b>
        miqdorda komissiya ushlab qoladi.</p>
    <p>2.2. Mijoz to'lovi Platforma hisobiga tushadi; Platforma komissiyani ushlab, qolgan mablag'ni
        Agentga (bank yoki karta orqali) o'tkazadi.</p>

    <h2>3. Tomonlarning majburiyatlari</h2>
    <p>3.1. Agent: xizmatlarni sifatli va o'z vaqtida bajarish; taqdim etgan rekvizitlar va
        ma'lumotlarning to'g'riligi uchun javobgar bo'lish; platforma qoidalariga rioya qilish.</p>
    <p>3.2. Platforma: marketplace ishlashini ta'minlash; to'lovlarni yuritish va hisobotlarni
        taqdim etish; nizolarda vositachilik qilish.</p>

    <h2>4. Amal qilish muddati</h2>
    <p>4.1. Shartnoma imzolangan (Agent tomonidan qo'l qo'yilib, muhr bosilib qayta yuklangan va
        Platforma menejeri tomonidan tasdiqlangan) paytdan kuchga kiradi va nomuayyan muddatga tuziladi.</p>

    <h2>5. Tomonlarning rekvizitlari</h2>
    <table class="parties">
        <tr>
            <td>
                <span class="label">PLATFORMA</span>
                <div class="row">«Reklama Bozor»</div>
                <div class="row muted">STIR (INN): ____________</div>
                <div class="row muted">Manzil: ____________</div>
                <div class="row muted">H/r: ____________</div>
                <div class="row muted">Bank / MFO: ____________</div>
                <div class="sign">Imzo / muhr: ______________</div>
            </td>
            <td>
                <span class="label">AGENT</span>
                <div class="row">{{ $profile->company_name ?: '—' }}</div>
                @if ($profile->legal_form)
                    <div class="row">Tashkiliy shakl: {{ $profile->legal_form }}</div>
                @endif
                <div class="row">Rahbar: {{ $profile->director_name ?: '—' }}</div>
                <div class="row">STIR (INN): {{ $profile->inn ?: '—' }}</div>
                <div class="row">Telefon: {{ $profile->phone ?: '—' }}</div>
                <div class="row">Manzil: {{ $profile->location_label ?: '—' }}</div>
                <div class="row">H/r: {{ $profile->bank_account ?: '—' }}</div>
                <div class="row">Bank: {{ $profile->bank_name ?: '—' }}</div>
                <div class="row">MFO: {{ $profile->mfo ?: '—' }}</div>
                <div class="sign">Imzo / muhr: ______________</div>
            </td>
        </tr>
    </table>

    <div class="draft">
        DRAFT — bu shablon namunaviy matn. Yakuniy huquqiy matn va Platforma rekvizitlari yurist
        tomonidan tasdiqlanishi shart. Hujjat identifikatori: RB-A-{{ $profile->id }}-{{ $version }}.
    </div>
</body>
</html>
