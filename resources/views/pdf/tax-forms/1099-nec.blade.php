{{-- IRS Form 1099-NEC — Nonemployee Compensation --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @page { size: letter; margin: 0; }
        body { font-family: 'Courier New', monospace; font-size: 11px; }
        .form-box { border: 2px solid black; }
        .field-label { font-size: 7px; color: #666; text-transform: uppercase; }
        .field-value { font-size: 12px; font-weight: bold; }
    </style>
</head>
<body class="bg-white">

    {{-- Copy B — For Recipient --}}
    <div class="p-8">
        <div class="form-box p-0">
            {{-- Header --}}
            <div class="flex border-b-2 border-black">
                <div class="w-1/2 p-3 border-r border-black">
                    <div class="field-label">Payer's name, street address, city or town, state or province, country, ZIP</div>
                    <div class="field-value mt-1">
                        {{ strtoupper($profile->entity_name ?? 'ZAO WEB DESIGN, LLC') }}<br>
                        {{ strtoupper($profile->entity_address ?? $profile->address ?? '') }}<br>
                        {{ strtoupper(($profile->city ?? '').', '.($profile->state ?? '').' '.($profile->zip ?? '')) }}
                    </div>
                </div>
                <div class="w-1/2">
                    <div class="flex">
                        <div class="w-1/2 p-2 border-r border-black border-b border-black">
                            <div class="field-label">Payer's TIN</div>
                            <div class="field-value">XX-XXX{{ substr($profile->entity_ein_encrypted ?? '0000', -4) }}</div>
                        </div>
                        <div class="w-1/2 p-2 border-b border-black">
                            <div class="field-label">Recipient's TIN</div>
                            <div class="field-value">{{ $contractor->getMaskedTin() }}</div>
                        </div>
                    </div>
                    <div class="p-2">
                        <div class="text-right">
                            <div class="text-xs text-gray-500">OMB No. 1545-0116</div>
                            <div class="text-2xl font-bold">{{ $year }}</div>
                            <div class="text-center">
                                <div class="text-lg font-bold">Form 1099-NEC</div>
                                <div class="text-xs">Nonemployee<br>Compensation</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Recipient info + amounts --}}
            <div class="flex border-b border-black">
                <div class="w-1/2 p-3 border-r border-black">
                    <div class="field-label">Recipient's name</div>
                    <div class="field-value mt-1">{{ strtoupper($contractor->vendor_name) }}</div>
                </div>
                <div class="w-1/2">
                    <div class="p-3 border-b border-black">
                        <div class="field-label">1 Nonemployee compensation</div>
                        <div class="field-value text-lg">${{ number_format($contractor->total_payments, 2) }}</div>
                    </div>
                    <div class="p-3">
                        <div class="field-label">4 Federal income tax withheld</div>
                        <div class="field-value">$0.00</div>
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="p-3 bg-gray-100 text-center">
                <div class="text-xs font-bold">Copy B — For Recipient</div>
                <div class="text-xs text-gray-600">This is important tax information and is being furnished to the IRS.</div>
                <div class="text-xs text-gray-600">If you are required to file a return, a negligence penalty or other sanction may be imposed on you if this income is taxable and the IRS determines that it has not been reported.</div>
            </div>
        </div>

        {{-- Instructions for recipient --}}
        <div class="mt-6 text-xs text-gray-600">
            <p class="font-bold text-gray-800 mb-2">Instructions for Recipient</p>
            <p>Box 1 shows the amount of nonemployee compensation paid to you. Report this amount on Schedule C (Form 1040) if you are self-employed, or on the appropriate tax form for your filing status.</p>
            <p class="mt-2">You must file a tax return if your net earnings from self-employment are $400 or more.</p>
        </div>
    </div>

    {{-- Copy C — For Payer's Records --}}
    <div class="break-before-page p-8">
        <div class="form-box p-0">
            <div class="flex border-b-2 border-black">
                <div class="w-1/2 p-3 border-r border-black">
                    <div class="field-label">Payer's name</div>
                    <div class="field-value mt-1">{{ strtoupper($profile->entity_name ?? 'ZAO WEB DESIGN, LLC') }}</div>
                </div>
                <div class="w-1/2 p-2 text-right">
                    <div class="text-2xl font-bold">{{ $year }}</div>
                    <div class="text-lg font-bold">Form 1099-NEC</div>
                    <div class="text-xs">Copy C — For Payer</div>
                </div>
            </div>
            <div class="flex border-b border-black">
                <div class="w-1/2 p-3 border-r border-black">
                    <div class="field-label">Recipient</div>
                    <div class="field-value">{{ strtoupper($contractor->vendor_name) }}</div>
                    <div class="text-xs mt-1">TIN: {{ $contractor->getMaskedTin() }}</div>
                </div>
                <div class="w-1/2 p-3">
                    <div class="field-label">1 Nonemployee compensation</div>
                    <div class="field-value text-lg">${{ number_format($contractor->total_payments, 2) }}</div>
                </div>
            </div>
            <div class="p-3 bg-gray-100 text-center text-xs">
                For Payer's Records — Do not send to IRS
            </div>
        </div>

        <div class="mt-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
            <p class="text-sm font-bold text-yellow-800">Filing Deadline: {{ $filing_deadline }}</p>
            <p class="text-xs text-yellow-700 mt-1">Copy A must be filed with the IRS. Copy B must be sent to the recipient. Both are due by {{ $filing_deadline }}.</p>
        </div>
    </div>
</body>
</html>
