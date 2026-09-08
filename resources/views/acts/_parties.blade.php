{{-- Requisites block shared by both acts: $left / $right are snapshot arrays. --}}
<table class="parties">
    <tr>
        <td>
            <span class="label">{{ $leftLabel }}</span>
            <div class="row"><b>{{ $left['legal_name'] ?? $left['company_name'] ?? $left['name'] ?? '—' }}</b></div>
            @if (! empty($left['inn']))
                <div class="row">INN: {{ $left['inn'] }}</div>
            @endif
            @if (! empty($left['address']))
                <div class="row">Manzil: {{ $left['address'] }}</div>
            @endif
            @if (! empty($left['bank_name']))
                <div class="row">Bank: {{ $left['bank_name'] }}</div>
            @endif
            @if (! empty($left['bank_account']))
                <div class="row">H/r: {{ $left['bank_account'] }}</div>
            @endif
            @if (! empty($left['mfo']))
                <div class="row">MFO: {{ $left['mfo'] }}</div>
            @endif
            @if (! empty($left['phone']))
                <div class="row">Tel: {{ $left['phone'] }}</div>
            @endif
            <div class="sign">Imzo / M.O‘. ____________________</div>
        </td>
        <td>
            <span class="label">{{ $rightLabel }}</span>
            <div class="row"><b>{{ $right['legal_name'] ?? $right['company_name'] ?? $right['name'] ?? '—' }}</b></div>
            @if (! empty($right['inn']))
                <div class="row">INN: {{ $right['inn'] }}</div>
            @endif
            @if (! empty($right['address']))
                <div class="row">Manzil: {{ $right['address'] }}</div>
            @endif
            @if (! empty($right['bank_name']))
                <div class="row">Bank: {{ $right['bank_name'] }}</div>
            @endif
            @if (! empty($right['bank_account']))
                <div class="row">H/r: {{ $right['bank_account'] }}</div>
            @endif
            @if (! empty($right['mfo']))
                <div class="row">MFO: {{ $right['mfo'] }}</div>
            @endif
            @if (! empty($right['phone']))
                <div class="row">Tel: {{ $right['phone'] }}</div>
            @endif
            <div class="sign">Imzo / M.O‘. ____________________</div>
        </td>
    </tr>
</table>
