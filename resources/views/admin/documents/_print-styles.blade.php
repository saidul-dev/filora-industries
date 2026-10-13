{{-- Same A4 print rules as the money receipts/payslip: only .print-area is
     printed, with the signature footer pushed to the bottom of the page. --}}
<style>
    @media print {
        body { background: #fff !important; }
        body * { visibility: hidden; }
        .print-area, .print-area * { visibility: visible; }
        .print-area {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            max-width: 100%;
            min-height: calc(297mm - 24mm);
            display: flex;
            flex-direction: column;
            box-shadow: none;
            border: none;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .print-footer {
            margin-top: auto !important;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        @page { size: A4; margin: 12mm; }
    }
</style>
