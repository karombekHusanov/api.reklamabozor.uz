<?php

/*
 | agent_offer.php (agentliklar uchun hamkorlik ommaviy ofertasi) ning inglizcha
 | tarjimasi — faqat ma'lumot uchun. Yuridik kuchga ega matn — o'zbekcha (kirill)
 | asl nusxa (resources/legal/agent_offer.php). Asl matn o'zgarsa, ushbu
 | tarjimani ham yangilang.
*/

return [
    'title' => 'PUBLIC OFFER FOR COOPERATION WITH AGENCIES OPERATING ON THE «PRB» PLATFORM',
    'sections' => [
        [
            'title' => '1. GENERAL PROVISIONS',
            'clauses' => [
                ['label' => '1.1.', 'text' => 'This document is a public offer drawn up in accordance with Articles 369–372 of the Civil Code of the Republic of Uzbekistan and constitutes an official proposal by «Imprint Business» LLC (hereinafter, the "Operator") addressed to advertising agencies and independent agents wishing to operate on the «PRB» platform.'],
                ['label' => '1.2.', 'text' => 'The Contractor\'s submission of an application for registration on the Platform and the Operator\'s approval thereof shall constitute full and unconditional acceptance of the offer and, in accordance with Article 370 of the Civil Code, shall be deemed equivalent to the conclusion of a written contract.'],
                ['label' => '1.3.', 'text' => 'The Operator is a technological intermediary that conducts settlements between the Client and the Contractor as a commission agent. This offer governs the commission relationship between the Operator and the Contractor.'],
            ],
        ],
        [
            'title' => '2. KEY DEFINITIONS',
            'clauses' => [
                ['label' => 'Platform —', 'text' => 'the «PRB» software owned by the Operator (mobile application, Telegram bot and mini-app).'],
                ['label' => 'Contractor —', 'text' => 'an advertising agency or independent agent registered on the Platform that directly performs Clients\' orders.'],
                ['label' => 'Client —', 'text' => 'an individual or legal entity that orders the Contractor\'s services through the Platform.'],
                ['label' => 'Order Value —', 'text' => 'the total value of the Order, from which the Initial Payment and the Final Payment (Client) are calculated.'],
                ['label' => 'Initial Payment —', 'text' => 'the portion paid by the Client when the Order is confirmed — 50 percent of the Order Value.'],
                ['label' => 'Final Payment (Client) —', 'text' => 'the remaining portion paid by the Client after the report on the readiness of the product/service — 50 percent of the Order Value; individuals pay before installation, while legal entities pay afterwards by way of exception.'],
                ['label' => 'Advance Payment —', 'text' => 'the portion transferred to the Contractor after the Order is confirmed — the percentage set out in the Tariff Schedule (base rate — 40%; 20–25% for new Contractors without a track record).'],
                ['label' => 'Final Payment —', 'text' => 'the remaining portion transferred to the Contractor upon completion of the work — 60 percent of the Order Value, less the Operator\'s commission.'],
                ['label' => 'Operator\'s Commission —', 'text' => 'the service fee withheld in favour of the Operator from each Order Value at the percentage set out in the Tariff Schedule.'],
                ['label' => 'Tariff Schedule —', 'text' => 'the document publicly announced by the Operator on the Platform that sets the commission, the advance share and the penalty rates.'],
                ['label' => 'Objection Window —', 'text' => 'the 48-hour period during which the Client may file a complaint regarding quality.'],
            ],
        ],
        [
            'title' => '3. SUBJECT MATTER OF THE CONTRACT',
            'clauses' => [
                ['label' => '3.1.', 'text' => 'The Operator provides the Contractor with the following: (a) the opportunity to connect with Clients through the Platform; (b) the conduct of settlements on Orders as a commission agent; (c) ensuring that payments are made on time.'],
                ['label' => '3.2.', 'text' => 'The Contractor undertakes to perform the service in accordance with the terms of the Order concluded with the Client, at the specified quality and within the specified time limits, and shall bear full responsibility therefor.'],
            ],
        ],
        [
            'title' => '4. REGISTRATION AND VERIFICATION (KYC)',
            'clauses' => [
                ['label' => '4.1.', 'text' => 'To register on the Platform, the Contractor shall submit the following documents: (a) a certificate confirming state registration / TIN; (b) a permit required for advertising placement activities or the passport of the advertising site (if available); (c) bank details; (d) an identity document of the responsible person.'],
                ['label' => '4.2.', 'text' => 'The Operator has the right to verify the submitted documents and to reject an application for registration on reasonable grounds.'],
                ['label' => '4.3.', 'text' => 'The Contractor shall notify the Operator of any changes in its details or legal status within 3 (three) business days.'],
            ],
        ],
        [
            'title' => '5. PAYMENT AND SETTLEMENT PROCEDURE',
            'clauses' => [
                ['label' => '5.1.', 'text' => 'When the Order is confirmed, the Client pays the Initial Payment (50 percent of the Order Value) to the Operator\'s account.'],
                ['label' => '5.2.', 'text' => 'After the Contractor confirms the Order, the portion of the Order Value set out in the Tariff Schedule (base rate — 40%) is transferred to the Contractor\'s bank account as the Advance Payment.'],
                ['label' => '5.3.', 'text' => 'After the Contractor submits a report confirming the readiness of the product/service, the Operator requests the Final Payment (Client) from the Client; a Client who is an individual pays it before installation, while a Client that is a legal entity pays it later by way of exception (in accordance with clause 5.6 of the Contractor offer).'],
                ['label' => '5.4.', 'text' => 'After the Client gives the "I have accepted the product" confirmation on the Platform (or after the Objection Window expires), the Final Settlement (60 percent of the Order Value, less the Operator\'s commission) is transferred to the Contractor.'],
                ['label' => '5.5.', 'text' => 'The Final Settlement to the Contractor is made irrespective of the Client\'s payment status: it is paid by the Operator within no more than 1 (one) month from the date the confirmation is given — even where a Client that is a legal entity has not yet paid, the Operator temporarily covers this amount from its own funds and guarantees timely payment to the Contractor.'],
                ['label' => '5.6.', 'text' => 'Any delay in payment or non-payment by a Client that is a legal entity is entirely a matter between the Operator and the Client; the Contractor\'s right to receive the Final Settlement is not affected thereby.'],
                ['label' => '5.7.', 'text' => 'The amount of the Operator\'s commission is set out in the Tariff Schedule and is openly displayed for each Order in the Contractor\'s personal account.'],
            ],
        ],
        [
            'title' => '6. CANCELLATION, PENALTIES AND LIABILITY',
            'clauses' => [
                ['label' => '6.0. Confirmation chain and cancellation window.', 'text' => 'After being selected by the Client, the Contractor completes the cost calculation table, confirms it itself and sends it to the Client. Once the Client confirms it, the Initial Payment (50 percent of the Order Value) is paid to the Operator\'s account; upon receipt of the funds, the Operator confirms the payment. Following the Operator\'s confirmation, for the period set out in the Tariff Schedule (initial value — 1 hour), the Client has the right to cancel the Order free of charge — in this case the Operator withholds a service fee of 5–10 percent of the Order Value as set out in the Tariff Schedule and refunds the remainder to the Client; no payment whatsoever is made to the Contractor at this stage (the Advance Payment will not yet have been transferred). After the specified period expires, the Advance Payment (40%) is automatically transferred to the Contractor\'s bank account.'],
                ['label' => '6.1. Refusal by the Contractor before the advance.', 'text' => 'If the Contractor voluntarily refuses the Order before the Advance Payment, this is reflected in the Contractor\'s rating system; repeated occurrences constitute grounds for excluding the Contractor from the Platform.'],
                ['label' => '6.2. Failure to start work after receiving the advance.', 'text' => 'If, after receiving the Advance Payment, the Contractor fails to start work within the specified time limit or refuses to perform it: (a) the Operator immediately refunds to the Client the portion remaining with it (10 percent of the Initial Payment); (b) the amount equal to the Advance (40%) is also immediately and temporarily covered by the Operator and refunded to the Client — as a result, the full amount of the Initial Payment is refunded to the Client; (c) this 40% amount is withheld (set off) from payments due to the Contractor under its subsequent Orders — by accepting this offer, the Contractor gives its prior consent to such withholding; (d) no new Orders are offered to the Contractor until the debt is fully repaid.'],
                ['label' => '6.3. Work of inadequate quality.', 'text' => 'If the Client files a reasonable complaint within the Objection Window, the Contractor is given the opportunity to remedy the defect at its own expense within 3–5 business days. If the Contractor fails to remedy the defect, the Operator assesses the situation on the basis of the evidence provided (photo/video) and withholds the amount of the established damage from the Contractor\'s Final Payment.'],
                ['label' => '6.4. Breach of time limits.', 'text' => 'If the Order deadline is breached through the Contractor\'s fault, a penalty set out in the Tariff Schedule (initial rate — 0.5% of the Order Value per day, but not exceeding the amount of the Final Payment) is withheld from the Contractor\'s payment for each day of delay.'],
                ['label' => '6.5. Delay caused by the Client.', 'text' => 'If the delay arises because the Client has failed to perform its own obligations, the time limit is extended accordingly and no penalty is applied to the Contractor.'],
                ['label' => '6.6. Force majeure.', 'text' => 'The Contractor shall not be liable in circumstances beyond the control of the Parties; with respect to costs already incurred, the Operator and the Contractor shall reach a separate agreement on an individual, case-by-case basis.'],
                ['label' => '6.7. Repeated breaches.', 'text' => 'If the circumstances provided for in this section recur within a certain period (in the number set out in the Tariff Schedule), the Operator has the right to temporarily exclude the Contractor from the Platform or to remove it from the register permanently.'],
            ],
        ],
        [
            'title' => '7. RATING SYSTEM',
            'clauses' => [
                ['label' => '7.1.', 'text' => 'After each completed Order, the Client rates the Contractor. The Contractor\'s rating is maintained openly on the Platform in a manner visible to other Clients.'],
                ['label' => '7.2.', 'text' => 'A Contractor whose rating falls below the minimum threshold set out in the Tariff Schedule may have the display of new Orders restricted or suspended.'],
            ],
        ],
        [
            'title' => '8. CONFIDENTIALITY AND INTELLECTUAL PROPERTY',
            'clauses' => [
                ['label' => '8.1.', 'text' => 'The Contractor shall use Clients\' data obtained through the Platform solely for the purpose of performing the relevant Order and shall not transfer such data to third parties.'],
                ['label' => '8.2.', 'text' => 'Portfolio materials uploaded to the Platform by the Contractor (images, videos, project samples) remain the property of the Contractor; the Operator shall have the right to display them on the Platform solely for the purpose of presenting the Contractor to Clients.'],
            ],
        ],
        [
            'title' => '9. DISPUTE RESOLUTION PROCEDURE',
            'clauses' => [
                ['label' => '9.1.', 'text' => 'All disputes arising between the Parties shall first be resolved through negotiations. If no agreement is reached, the dispute shall be considered by the competent court in accordance with the applicable legislation of the Republic of Uzbekistan.'],
            ],
        ],
        [
            'title' => '10. TERM AND TERMINATION OF THE CONTRACT',
            'clauses' => [
                ['label' => '10.1.', 'text' => 'This offer enters into force from the moment of its acceptance and remains in effect for an indefinite period.'],
                ['label' => '10.2.', 'text' => 'Either party may terminate this cooperation unilaterally at any time, provided that there are no Orders in the process of performance.'],
                ['label' => '10.3.', 'text' => 'The Operator has the right to unilaterally amend the offer and the Tariff Schedule; amendments enter into force from the date of their publication on the Platform.'],
            ],
        ],
        [
            'title' => '11. CHANGING THE SCOPE AND TIME LIMITS OF THE ORDER',
            'clauses' => [
                ['label' => '11.0.', 'text' => 'Criterion: changes made during the first 1/3 (one third) of the Order performance period are deemed an "early change", and changes made during the remaining 2/3 are deemed a "late change".'],
                ['label' => '11.1. Early change — reduction in scope (Client\'s initiative).', 'text' => 'If the Client reduces the scope of the Order during the early change period, the Order Value and the Advance Payment due to the Contractor are recalculated in accordance with the new scope. No penalty is applied to the Contractor.'],
                ['label' => '11.2. Early change — increase in scope (Client\'s initiative).', 'text' => 'If the Client increases the scope of the Order during the early change period, the additional amount is added to the Advance Payment. If the Contractor cannot perform the additional scope within the original time limit, a new time limit is agreed between the Parties and formalised as a written supplementary agreement. The Contractor has the right to refuse the additional scope or the new time limit, in which case no penalty is applied to it. If the change is within the limits set out in the Tariff Schedule and does not affect the time limit, it may be formalised without a written document, by electronic confirmation within the Platform; where the time limit changes or the change goes beyond the limits of the Tariff Schedule, a written supplementary agreement is mandatory.'],
                ['label' => '11.3. Early change — Contractor\'s initiative.', 'text' => 'During the early change period, the Contractor may, for good reason, propose changes to the terms of the Order; the proposal is presented to the Client through the Operator. If the need for the change arose as a result of the Contractor\'s own error, the additional costs remain the responsibility of the Contractor.'],
                ['label' => '11.4. Late change — reduction in scope (Client\'s initiative).', 'text' => 'If the Client reduces the scope of the Order during the late change period, the Final Payment due to the Contractor is calculated from the originally agreed Order Value — the reduced scope does not affect the Contractor\'s fee.'],
                ['label' => '11.5. Late change — increase in scope (Client\'s initiative).', 'text' => 'If the Client increases the scope of the Order during the late change period, the additional part is formalised as a separate order and receives a separate Advance/Final Payment. A delay attributable to the original Order time limit is deemed to be at the Client\'s initiative, and no penalty is applied to the Contractor.'],
                ['label' => '11.6. Late change — Contractor\'s initiative.', 'text' => 'If, during the late change period, the Contractor is compelled to change the terms of the Order (for example, due to a shortage of resources), this is deemed a planning error on the part of the Contractor; the Final Payment for the unperformed part is reduced accordingly and this is reflected in the rating system.'],
            ],
        ],
        [
            'title' => '12. QUICK REQUEST ROUTE — PROCEDURE FOR SUBMITTING OFFERS',
            'clauses' => [
                ['label' => '12.1.', 'text' => 'If the estimated Order Value indicated by the Client in the Request is below the threshold set out in the Tariff Schedule (initial value — 100,000,000 soums), the Order may be created only via the «Quick request» route; for orders of a higher value, the Client chooses at its discretion either the «Quick request» or the «Tender» route. The payment, cancellation and amendment procedures provided for in Sections 5, 6 and 11 of this offer do not apply to the «Quick request» route.'],
                ['label' => '12.2.', 'text' => 'All registered Contractors have the right to view all Requests on the Platform free of charge at all times.'],
                ['label' => '12.3.', 'text' => 'Submitting an offer (response) to a Request is chargeable — for each individual offer, the Contractor makes a payment at the price set out in the Tariff Schedule (initial value — 1,000 soums) by card before sending the offer.'],
                ['label' => '12.4.', 'text' => 'Until the payment has been successfully completed, the offer is not sent to the Request. Pre-funding of a balance or purchase of packages does not apply.'],
                ['label' => '12.5.', 'text' => 'Each offer is paid for separately — a previous payment does not apply to subsequent offers.'],
                ['label' => '12.6.', 'text' => 'Once the Client selects an offer, the contact details of the selected Contractor are disclosed automatically; no additional payment is required for this.'],
                ['label' => '12.7.', 'text' => 'Under this route, the performance, payment and quality of the Order depend entirely on the mutual agreement between the Client and the Contractor; the Operator is not an intermediary, guarantor or liable party at these stages.'],
                ['label' => '12.8.', 'text' => 'The response price and the threshold amount specified in clause 12.1 are set out in the Tariff Schedule and may be changed unilaterally by the Operator.'],
            ],
        ],
    ],
    'requisites_title' => '13. OPERATOR\'S DETAILS',
    'requisites' => [
        ['label' => 'Name', 'value' => '«Imprint Business» LLC'],
        ['label' => 'Address', 'value' => 'Apartment 3, Building 22/1, Taras Shevchenko Street, Afrosiyob Mahalla, Mirobod District, Tashkent City'],
        ['label' => 'TIN', 'value' => '311 937 654'],
        ['label' => 'Account number', 'value' => '20208000707204832001'],
        ['label' => 'Bank', 'value' => '«Kapitalbank» JSCB, MFO 01158, SWIFT: KACHUZ22'],
        ['label' => 'Director', 'value' => 'Khasanov Sherdil Vokhobjon ugli'],
    ],
];
