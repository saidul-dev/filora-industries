<?php

namespace Tests\Feature\SalesOrderImport;

use App\Services\SalesOrderImport\SheetParseException;
use App\Services\SalesOrderImport\SheetParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Feature\Concerns\BuildsOrderWorkbooks;
use Tests\TestCase;

class SheetParserTest extends TestCase
{
    use BuildsOrderWorkbooks;

    private function sampleLines(): array
    {
        return [
            ['style' => 'TS472302', 'description' => '100% Spun Polyester, 50/2', 'color' => '0160', 'quantity' => 65, 'price' => 0.2186, 'total' => 14.21],
            ['style' => 'TS472302', 'description' => '100% Spun Polyester, 50/2', 'color' => '0298', 'quantity' => 65, 'price' => 0.2186, 'total' => 14.21, 'remarks' => 'Rush'],
            ['style' => 'TS472304', 'description' => '100% Filament Polyester, 150D', 'color' => '0001', 'quantity' => 77, 'price' => 0.2210, 'total' => 17.02],
        ];
    }

    public function test_reads_header_fields(): void
    {
        $header = (new SheetParser)->parse($this->makeOrderWorkbook($this->sampleLines()))['header'];

        $this->assertSame('Mosharaf Apparels Studio Ltd.', $header['customer_name']);
        $this->assertSame('Baniarchala, Bhabanipur, Gazipur sadar', $header['customer_address']);
        $this->assertSame('BHPC & HENRY COTTON', $header['buyer_name']);
        $this->assertSame('MASL : 1839-1859', $header['order_number']);
        $this->assertSame('2026-09-22', $header['booking_date']);
        $this->assertSame('2026-09-30', $header['delivery_start_date']);
        $this->assertSame('2026-10-05', $header['delivery_close_date']); // "5/10/2026" is d/m/Y
        $this->assertSame('T-SHIRT/POLO-SHIRT', $header['product_banner']);
        $this->assertSame('SHORIFUL ISLAM', $header['from']['name']);
        $this->assertSame('ASST. MANAGER (M&M)', $header['from']['designation']);
        $this->assertSame('shoriful@mosharafgroup.com', $header['email']);
        $this->assertSame('+8801810-007040.', $header['contact']);
        $this->assertSame('G M JAFOR SADEK GALIB, MOSHARAF APPARELS STUDIO LTD, galib@mosharafgroup.com', $header['cc']);
    }

    public function test_reads_lines_with_raw_price_and_text_color(): void
    {
        $lines = (new SheetParser)->parse($this->makeOrderWorkbook($this->sampleLines()))['lines'];

        $this->assertCount(3, $lines);
        $this->assertSame('TS472302', $lines[0]['style']);
        $this->assertSame('100% Spun Polyester, 50/2', $lines[0]['description']);
        $this->assertSame('0160', $lines[0]['color']);
        $this->assertSame(65.0, $lines[0]['quantity']);
        $this->assertSame(2000.0, $lines[0]['length_per_unit']);
        $this->assertSame(0.2186, $lines[0]['unit_price']); // shown as $0.22 in the sheet
        $this->assertSame(14.21, $lines[0]['sheet_total']);
        $this->assertNull($lines[0]['remarks']);
        $this->assertSame('Rush', $lines[1]['remarks']);
        $this->assertNull($lines[0]['warning']);
    }

    public function test_numeric_color_keeps_its_leading_zero(): void
    {
        $path = $this->makeOrderWorkbook($this->sampleLines());
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $book->getActiveSheet()->setCellValue('D18', 160);
        $book->getActiveSheet()->getStyle('D18')->getNumberFormat()->setFormatCode('0000');
        (new Xlsx($book))->save($path);

        $this->assertSame('0160', (new SheetParser)->parse($path)['lines'][0]['color']);
    }

    public function test_stops_before_totals_row(): void
    {
        $lines = (new SheetParser)->parse($this->makeOrderWorkbook($this->sampleLines()))['lines'];

        $this->assertSame(['TS472302', 'TS472302', 'TS472304'], array_column($lines, 'style'));
    }

    public function test_flags_total_mismatch(): void
    {
        $lines = $this->sampleLines();
        $lines[2]['total'] = 20.00;

        $parsed = (new SheetParser)->parse($this->makeOrderWorkbook($lines))['lines'];

        $this->assertNull($parsed[0]['warning']);
        $this->assertStringContainsString('17.02', $parsed[2]['warning']);
    }

    public function test_rejects_sheet_without_line_table(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setCellValue('A1', 'Just a note');
        $path = tempnam(sys_get_temp_dir(), 'order').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->expectException(SheetParseException::class);
        (new SheetParser)->parse($path);
    }

    public function test_rejects_non_numeric_quantity(): void
    {
        $lines = $this->sampleLines();
        $lines[1]['quantity'] = 'sixty';
        $lines[1]['total'] = 0;

        $this->expectException(SheetParseException::class);
        $this->expectExceptionMessage('Row 19');
        (new SheetParser)->parse($this->makeOrderWorkbook($lines));
    }
}
