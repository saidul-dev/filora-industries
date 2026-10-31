<?php

namespace Tests\Feature\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes an .xlsx laid out like the customer order sheets (Mosharaf
 * Apparels sample): title block, header labels in columns A and I with
 * values beside them, the "Sl. No#" line table, then blank rows and a
 * totals row.
 */
trait BuildsOrderWorkbooks
{
    /**
     * @param  array<int, array{style: string, description: string, color: string, quantity: float, length?: float, price: float, total?: float, remarks?: string}>  $lines
     */
    protected function makeOrderWorkbook(array $lines, array $header = []): string
    {
        $header = array_merge([
            'customer_name' => 'Mosharaf Apparels Studio Ltd.',
            'customer_address' => 'Baniarchala, Bhabanipur, Gazipur sadar',
            'buyer_name' => 'BHPC & HENRY COTTON',
            'order_number' => 'MASL : 1839-1859',
            'booking_date' => '2026-09-22',     // written as a real Excel date
            'delivery_start_date' => '2026-09-30',
            'delivery_close_date' => '5/10/2026', // written as text, d/m/Y
            'product' => 'T-SHIRT/POLO-SHIRT',
        ], $header);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();

        $sheet->setCellValue('A1', $header['customer_name']);
        $sheet->setCellValue('A2', $header['customer_address']);
        $sheet->setCellValue('A3', "Product:  {$header['product']}");

        $sheet->setCellValue('A5', 'Customer Name');
        $sheet->setCellValue('B5', $header['customer_name']);
        $sheet->setCellValue('A6', 'Customer Address');
        $sheet->setCellValue('B6', $header['customer_address']);
        $sheet->setCellValue('A7', 'Buyer Name');
        $sheet->setCellValue('B7', $header['buyer_name']);

        $sheet->setCellValue('I5', 'Order Number');
        $sheet->setCellValue('J5', $header['order_number']);
        $sheet->setCellValue('I6', 'Booking Date:');
        $this->writeDate($sheet, 'J6', $header['booking_date']);
        $sheet->setCellValue('I7', 'Delivery Start Date:');
        $this->writeDate($sheet, 'J7', $header['delivery_start_date']);
        $sheet->setCellValue('I8', 'Delivery Close Date');
        $this->writeDate($sheet, 'J8', $header['delivery_close_date']);

        $sheet->setCellValue('A10', 'From');
        $sheet->setCellValue('B10', 'SHORIFUL ISLAM');
        $sheet->setCellValue('B11', 'ASST. MANAGER (M&M)');
        $sheet->setCellValue('B12', 'MOSHARAF APPARELS STUDIO LTD');
        $sheet->setCellValue('B13', 'Baniarchala, Bhabanipur, Gazipur Sadar, Gazipur');
        $sheet->setCellValue('I10', 'CC');
        $sheet->setCellValue('J10', 'G M JAFOR SADEK GALIB');
        $sheet->setCellValue('J11', 'MOSHARAF APPARELS STUDIO LTD');
        $sheet->setCellValue('J12', 'galib@mosharafgroup.com');
        $sheet->setCellValue('A14', 'email.');
        $sheet->setCellValue('B14', 'shoriful@mosharafgroup.com');
        $sheet->setCellValue('A15', 'Contact');
        $sheet->setCellValue('B15', '+8801810-007040.');

        $headings = ['Sl. No#', 'Style', 'Item Description', 'Color', "Booking Quantity (in\nCone)", "Length/Cone\n(in Meter)", 'Price/Cone (in USD)', "Total Price (in\nUSD)", 'Remarks'];
        foreach ($headings as $i => $heading) {
            $sheet->setCellValue([$i + 1, 17], $heading);
        }

        $row = 18;
        foreach ($lines as $i => $line) {
            $sheet->setCellValue("A{$row}", $i + 1);
            $sheet->setCellValue("B{$row}", $line['style']);
            $sheet->setCellValue("C{$row}", $line['description']);
            // Shade codes are typed as text so "0160" keeps its zero.
            $sheet->setCellValueExplicit("D{$row}", $line['color'], DataType::TYPE_STRING);
            $sheet->setCellValue("E{$row}", $line['quantity']);
            $sheet->setCellValue("F{$row}", $line['length'] ?? 2000);
            $sheet->setCellValue("G{$row}", $line['price']);
            $sheet->getStyle("G{$row}")->getNumberFormat()->setFormatCode('"$"0.00');
            $sheet->setCellValue("H{$row}", $line['total'] ?? round($line['quantity'] * $line['price'], 2));
            if (isset($line['remarks'])) {
                $sheet->setCellValue("I{$row}", $line['remarks']);
            }
            $row++;
        }

        // Two blank rows, then a totals row with no style/description.
        $row += 2;
        $sheet->setCellValue("G{$row}", 'Total');
        $sheet->setCellValue("H{$row}", '=SUM(H18:H'.($row - 3).')');

        $path = tempnam(sys_get_temp_dir(), 'order').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }

    private function writeDate($sheet, string $cell, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $sheet->setCellValue($cell, ExcelDate::PHPToExcel(new \DateTime($value)));
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        } else {
            $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
        }
    }
}
