<?php

namespace App\Services\SalesOrderImport;

use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads a customer's thread-order sheet (first worksheet, one order) into
 * plain arrays — no database access. Header values are found by their
 * label rather than a fixed cell address, and the line table by its
 * "Sl. No" heading row, so small layout shifts between customers' sheets
 * don't break it.
 *
 * Returns ['header' => [...], 'lines' => [[...], ...]] — see the keys
 * built in header() and lines().
 */
class SheetParser
{
    /** Header label (normalised) => result key. */
    private const LABELS = [
        'customer name' => 'customer_name',
        'customer address' => 'customer_address',
        'buyer name' => 'buyer_name',
        'order number' => 'order_number',
        'order no' => 'order_number',
        'booking date' => 'booking_date',
        'delivery start date' => 'delivery_start_date',
        'delivery close date' => 'delivery_close_date',
        'from' => 'from',
        'cc' => 'cc',
        'email' => 'email',
        'e mail' => 'email',
        'contact' => 'contact',
    ];

    private const DATE_KEYS = ['booking_date', 'delivery_start_date', 'delivery_close_date'];

    /** Line-table column (result key) => text its heading contains. */
    private const COLUMNS = [
        'style' => 'style',
        'description' => 'description',
        'color' => 'colo',
        'quantity' => 'quantity',
        'length_per_unit' => 'length',
        'unit_price' => 'price/',
        'sheet_total' => 'total',
        'remarks' => 'remark',
    ];

    public function parse(string $path): array
    {
        try {
            $sheet = IOFactory::load($path)->getSheet(0);
        } catch (Throwable) {
            throw new SheetParseException('This file could not be opened as an Excel workbook.');
        }

        $tableRow = $this->findTableRow($sheet);

        if ($tableRow === null) {
            throw new SheetParseException('No line table found — the sheet needs a heading row starting with "Sl. No".');
        }

        $lines = $this->lines($sheet, $tableRow);

        if (! $lines) {
            throw new SheetParseException('The line table has no order lines.');
        }

        return ['header' => $this->header($sheet, $tableRow), 'lines' => $lines];
    }

    private function header(Worksheet $sheet, int $tableRow): array
    {
        $header = array_fill_keys(array_unique(array_values(self::LABELS)), null);
        $header['from'] = ['name' => null, 'designation' => null, 'company' => null, 'address' => null];
        $header['product_banner'] = null;
        $lastCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($row = 1; $row < $tableRow; $row++) {
            for ($col = 1; $col <= $lastCol; $col++) {
                $text = $this->text($sheet->getCell([$col, $row]));

                if ($text === null) {
                    continue;
                }

                // "Product:  T-SHIRT/POLO-SHIRT" — label and value in one cell.
                if ($header['product_banner'] === null && preg_match('/^product\s*:\s*(.*)$/i', $text, $m)) {
                    $header['product_banner'] = trim($m[1]) ?: $this->text($this->valueRightOf($sheet, $row, $col, $lastCol));

                    continue;
                }

                $key = self::LABELS[$this->normaliseLabel($text)] ?? null;

                if ($key === null || ($header[$key] !== null && $key !== 'from')) {
                    continue;
                }

                $valueCell = $this->valueRightOf($sheet, $row, $col, $lastCol);

                if ($valueCell === null) {
                    continue;
                }

                $header[$key] = match (true) {
                    in_array($key, self::DATE_KEYS, true) => $this->date($valueCell),
                    $key === 'from' => $this->fromBlock($this->block($sheet, $valueCell, $col, $tableRow)),
                    $key === 'cc' => implode(', ', $this->block($sheet, $valueCell, $col, $tableRow)),
                    default => $this->text($valueCell),
                };
            }
        }

        return $header;
    }

    /**
     * A label's value plus the lines under it in the same column — the
     * "From"/"CC" blocks span several rows with no labels of their own.
     * Stops at a blank cell or a row where the label column has text.
     *
     * @return array<int, string>
     */
    private function block(Worksheet $sheet, Cell $first, int $labelCol, int $tableRow): array
    {
        $col = Coordinate::columnIndexFromString($first->getColumn());
        $values = [];

        for ($row = $first->getRow(); $row < $tableRow; $row++) {
            if ($row > $first->getRow() && $this->text($sheet->getCell([$labelCol, $row])) !== null) {
                break;
            }

            $text = $this->text($sheet->getCell([$col, $row]));

            if ($text === null) {
                break;
            }

            $values[] = $text;
        }

        return $values;
    }

    private function fromBlock(array $lines): array
    {
        return [
            'name' => $lines[0] ?? null,
            'designation' => $lines[1] ?? null,
            'company' => $lines[2] ?? null,
            'address' => isset($lines[3]) ? implode(', ', array_slice($lines, 3)) : null,
        ];
    }

    private function findTableRow(Worksheet $sheet): ?int
    {
        $lastCol = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), 30);

        foreach ($sheet->getRowIterator() as $row) {
            for ($col = 1; $col <= $lastCol; $col++) {
                $text = $this->text($sheet->getCell([$col, $row->getRowIndex()]));

                if ($text !== null && preg_match('/^sl\.?\s*no/i', $text)) {
                    return $row->getRowIndex();
                }
            }
        }

        return null;
    }

    private function lines(Worksheet $sheet, int $tableRow): array
    {
        $lastCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $columns = [];

        for ($col = 1; $col <= $lastCol; $col++) {
            $heading = mb_strtolower(preg_replace('/\s+/', ' ', (string) $this->text($sheet->getCell([$col, $tableRow]))));

            foreach (self::COLUMNS as $key => $needle) {
                if (! isset($columns[$key]) && $heading !== '' && str_contains(str_replace(' ', '', $heading), str_replace(' ', '', $needle))) {
                    $columns[$key] = $col;
                    break;
                }
            }
        }

        foreach (['description', 'quantity', 'unit_price'] as $required) {
            if (! isset($columns[$required])) {
                throw new SheetParseException("The line table has no \"{$required}\" column.");
            }
        }

        $lines = [];
        $highestRow = $sheet->getHighestDataRow();

        for ($row = $tableRow + 1; $row <= $highestRow; $row++) {
            $cell = fn (string $key) => isset($columns[$key]) ? $sheet->getCell([$columns[$key], $row]) : null;

            $style = $this->text($cell('style'));
            $description = $this->text($cell('description'));

            if ($style === null && $description === null) {
                break;
            }

            $quantity = $this->number($cell('quantity'));
            $price = $this->number($cell('unit_price'));

            if ($quantity === null || $quantity <= 0) {
                throw new SheetParseException("Row {$row}: booking quantity is missing or not a number.");
            }

            if ($price === null) {
                throw new SheetParseException("Row {$row}: price is missing or not a number.");
            }

            $sheetTotal = $this->number($cell('sheet_total'));
            $ourTotal = round($quantity * $price, 2);

            $lines[] = [
                'row' => $row,
                'style' => $style,
                'description' => $description,
                'color' => $this->text($cell('color')),
                'quantity' => $quantity,
                'length_per_unit' => $this->number($cell('length_per_unit')),
                'unit_price' => $price,
                'sheet_total' => $sheetTotal,
                'remarks' => $this->text($cell('remarks')),
                'warning' => $sheetTotal !== null && abs($ourTotal - $sheetTotal) > 0.01
                    ? sprintf('Sheet total %.2f differs from quantity × price = %.2f.', $sheetTotal, $ourTotal)
                    : null,
            ];
        }

        return $lines;
    }

    private function valueRightOf(Worksheet $sheet, int $row, int $col, int $lastCol): ?Cell
    {
        for ($c = $col + 1; $c <= $lastCol; $c++) {
            $cell = $sheet->getCell([$c, $row]);

            if ($this->text($cell) !== null) {
                // A neighbouring label (e.g. the right-hand header column) is
                // not this label's value.
                return isset(self::LABELS[$this->normaliseLabel($this->text($cell))]) ? null : $cell;
            }
        }

        return null;
    }

    /**
     * The text a person sees, except that numbers formatted as text-like
     * codes (a color typed as 0160 into a number cell) keep their format.
     */
    private function text(?Cell $cell): ?string
    {
        if ($cell === null) {
            return null;
        }

        $value = $cell->getValue();

        if ($value === null) {
            return null;
        }

        $text = is_numeric($value) && ! is_string($value)
            ? $cell->getFormattedValue()
            : (string) $cell->getCalculatedValue();

        $text = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $text));

        return $text === '' ? null : $text;
    }

    /** The raw value — never the rounded display ($0.22 for 0.2186). */
    private function number(?Cell $cell): ?float
    {
        if ($cell === null || $cell->getValue() === null) {
            return null;
        }

        $value = $cell->getCalculatedValue();

        if (is_string($value)) {
            $value = str_replace([',', '$', ' '], '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /** Excel date serial, or text in day-first form ("5/10/2026" = 5 Oct). */
    private function date(Cell $cell): ?string
    {
        $value = $cell->getCalculatedValue();

        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }

        $value = trim((string) $value);

        if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $value, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $value, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    private function normaliseLabel(string $text): string
    {
        return trim(preg_replace('/[^a-z]+/', ' ', mb_strtolower($text)));
    }
}
