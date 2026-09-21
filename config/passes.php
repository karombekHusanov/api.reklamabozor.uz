<?php

return [
    /*
    | Propusk (agent access pass) enforcement for claiming Tezkor requests.
    | Off until Faza 2 ships the payment side; {@see \App\Services\Order\PassGate}.
    */
    'enforce' => (bool) env('PASSES_ENFORCE', false),
];
