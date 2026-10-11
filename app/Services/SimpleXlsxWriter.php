<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * A tiny, dependency-free writer for single-sheet `.xlsx` (OOXML SpreadsheetML)
 * workbooks. It produces a real Excel file — one Excel (and LibreOffice/Numbers)
 * opens natively as a spreadsheet, unlike a CSV — without pulling in a heavy
 * library such as PhpSpreadsheet.
 *
 * Scope is intentionally minimal, matching what the reports here need:
 *   - one worksheet with a caller-supplied name;
 *   - rows of cells that are either strings (inline strings) or numbers;
 *   - numbers stay numeric so spreadsheets sum/format them; strings are stored
 *     as inline strings (no shared-strings table), which keeps the writer small
 *     and the output self-contained.
 *
 * It is NOT a general-purpose writer: no styling, formulae, multiple sheets, or
 * dates. Money is passed as a numeric major-unit value (e.g. 125.00) so it lands
 * in a cell the spreadsheet can total.
 *
 * Build the file in memory and hand the bytes to a streamed/`Response`
 * download. A `ZipArchive`-less PHP build cannot produce xlsx; the writer throws
 * so the caller can fall back to CSV rather than emit a corrupt file.
 */
class SimpleXlsxWriter
{
    /** @var list<list<string|int|float|null>> */
    private array $rows = [];

    public function __construct(private readonly string $sheetName = 'Sheet1') {}

    /**
     * Append one row. Each cell is a string (written as text), an int/float
     * (written as a number), or null (an empty cell).
     *
     * @param  list<string|int|float|null>  $cells
     */
    public function addRow(array $cells): self
    {
        $this->rows[] = $cells;

        return $this;
    }

    /**
     * Append a blank spacer row.
     */
    public function addBlankRow(): self
    {
        $this->rows[] = [];

        return $this;
    }

    /**
     * Build the workbook and return the raw `.xlsx` bytes.
     *
     * @throws RuntimeException when the Zip extension is unavailable or the
     *                          archive cannot be assembled.
     */
    public function build(): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The Zip PHP extension is required to write .xlsx files.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($tmp === false) {
            throw new RuntimeException('Could not create a temporary file for the .xlsx workbook.');
        }

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Could not open the .xlsx archive for writing.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheetXml());

        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        if ($bytes === false) {
            throw new RuntimeException('Could not read the assembled .xlsx workbook.');
        }

        return $bytes;
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.$this->escape($this->sheetTabName()).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';
    }

    private function sheetXml(): string
    {
        $sheetData = '';

        foreach ($this->rows as $rowIndex => $cells) {
            $rowNumber = $rowIndex + 1;
            $sheetData .= '<row r="'.$rowNumber.'">';

            foreach ($cells as $colIndex => $value) {
                $ref = $this->columnLetter($colIndex).$rowNumber;
                $sheetData .= $this->cellXml($ref, $value);
            }

            $sheetData .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$sheetData.'</sheetData>'
            .'</worksheet>';
    }

    /**
     * One cell. Numbers are written as numeric cells (default type); strings as
     * inline strings (`t="inlineStr"`). Null/blank renders as an empty cell.
     */
    private function cellXml(string $ref, string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '<c r="'.$ref.'"/>';
        }

        if (is_int($value) || is_float($value)) {
            return '<c r="'.$ref.'"><v>'.$value.'</v></c>';
        }

        // Numeric-looking strings are still written as text to preserve leading
        // zeros / references; callers pass real numbers for numeric columns.
        return '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$this->escape($value).'</t></is></c>';
    }

    /**
     * Zero-based column index to its spreadsheet letter (0 => A, 26 => AA).
     */
    private function columnLetter(int $index): string
    {
        $letter = '';

        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $letter = chr($i % 26 + 65).$letter;
        }

        return $letter;
    }

    /**
     * Excel limits a sheet-tab name to 31 chars and forbids `: \ / ? * [ ]`.
     */
    private function sheetTabName(): string
    {
        $name = preg_replace('/[:\\\\\/?*\[\]]/', ' ', $this->sheetName) ?? 'Sheet1';
        $name = trim($name);

        return $name === '' ? 'Sheet1' : mb_substr($name, 0, 31);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
