<?php

namespace Tests\Unit;

use App\Support\Csv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CsvTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function cells(): array
    {
        return [
            'equals formula' => ['=HYPERLINK("http://x")', '\'=HYPERLINK("http://x")'],
            'plus' => ['+44 7700 900000', '\'+44 7700 900000'],
            'minus text' => ['-cmd', '\'-cmd'],
            'at sign' => ['@SUM(A1)', '\'@SUM(A1)'],
            'leading tab' => ["\t=1", "'\t=1"],
            'plain text' => ['Jane Smith', 'Jane Smith'],
            'negative money string stays numeric' => ['-12.50', '-12.50'],
            'integer' => [5, 5],
            'null' => [null, null],
            'empty string' => ['', ''],
        ];
    }

    #[DataProvider('cells')]
    public function test_cell_neutralises_formulas_only(mixed $input, mixed $expected): void
    {
        $this->assertSame($expected, Csv::cell($input));
    }

    public function test_put_writes_a_neutralised_row(): void
    {
        $stream = fopen('php://memory', 'w+');

        Csv::put($stream, ['=1+1', 'ok']);
        rewind($stream);

        $this->assertSame("'=1+1,ok\n", stream_get_contents($stream));
    }
}
