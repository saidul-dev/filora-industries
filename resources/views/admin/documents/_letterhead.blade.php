{{-- Company letterhead for printed order-to-delivery documents (PI, PO,
     Delivery Challan). Expects $company, $documentTitle, $documentNo, $documentDate. --}}
<div class="h-1.5 -mx-8 -mt-8 mb-6 rounded-t-2xl bg-brand-800"></div>
<div class="flex items-start justify-between gap-6 border-b-2 border-brand-800 pb-6">
    <div class="flex items-center gap-3">
        @if ($company->logo_url)
        <img src="{{ $company->logo_url }}" alt="{{ $company->name }}" class="h-14 w-14 rounded-lg object-cover">
        @endif
        <div>
            <h1 class="text-xl font-bold text-brand-800">{{ $company->name }}</h1>
            @if ($company->address)<p class="text-xs text-slate-500">{{ $company->address }}</p>@endif
            @if ($company->phone || $company->email)
            <p class="text-xs text-slate-500">{{ collect([$company->phone, $company->email])->filter()->implode(' · ') }}</p>
            @endif
            @if ($company->vat_registration_no || $company->bin_no)
            <p class="text-xs text-slate-500">
                @if ($company->vat_registration_no) {{ __('VAT') }}: {{ $company->vat_registration_no }} @endif
                @if ($company->bin_no) &nbsp;&nbsp; {{ __('BIN') }}: {{ $company->bin_no }} @endif
            </p>
            @endif
        </div>
    </div>
    <div class="text-right">
        <h2 class="text-lg font-bold uppercase tracking-wide text-brand-800">{{ $documentTitle }}</h2>
        <p class="text-sm font-semibold text-slate-800">{{ $documentNo }}</p>
        <p class="text-xs text-slate-500">{{ $documentDate->format('d M, Y') }}</p>
    </div>
</div>
