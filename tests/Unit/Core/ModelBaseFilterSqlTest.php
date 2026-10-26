<?php declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\ModelBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FilteredDailyPartModel extends ModelBase
{
    static public $tablename = 'dailyparts';
}

final class FilteredMissingTableModel extends ModelBase
{
    static public $tablename = 'missingtable';
}

final class ModelBaseFilterSqlTest extends TestCase
{
    private $originalschema;
    private array $violations = [];

    protected function setUp(): void
    {
        $this->originalschema = $GLOBALS['dbschema'] ?? null;
        $GLOBALS['dbschema'] = [
            'dailyparts' => [
                'iddailypart' => ['type' => 'int', 'primary' => true],
                'date' => ['type' => 'int'],
                'status' => ['type' => 'int'],
            ],
            'pages' => [
                'idpage' => ['type' => 'int', 'primary' => true],
                'stateid' => ['type' => 'int'],
            ],
        ];
        $this->violations = [];
        ModelBase::$onviolation = function (string $table, string $message) {
            $this->violations[] = $message;
        };
    }

    protected function tearDown(): void
    {
        $GLOBALS['dbschema'] = $this->originalschema;
        ModelBase::$onviolation = null;
    }

    public static function droppedFilters(): array
    {
        return [
            'injected table' => ['status', ['mode' => 'EQUAL', 'table' => 'dailyparts WHERE 1=1 OR 1', 'values' => 1]],
            'injected field on unknown key' => ['x', ['mode' => 'EQUAL', 'field' => 'status OR 1=1', 'values' => 1]],
            'table not in schema' => ['status', ['mode' => 'EQUAL', 'table' => 'users', 'values' => 1]],
            'column not in given table' => ['status', ['mode' => 'EQUAL', 'table' => 'pages', 'values' => 1]],
            'unknown mode' => ['status', ['mode' => 'FOO', 'values' => 1]],
            'non-string mode' => ['status', ['mode' => ['EQUAL'], 'values' => 1]],
            'array in EQUAL' => ['status', ['mode' => 'EQUAL', 'values' => [1, 2]]],
            'array in NOT_EQUAL' => ['status', ['mode' => 'NOT_EQUAL', 'values' => [0]]],
            'array in GTE' => ['date', ['mode' => 'GTE', 'values' => [20260101]]],
            'array in LIKE' => ['status', ['mode' => 'LIKE', 'values' => ['ab']]],
            'scalar in IN' => ['status', ['mode' => 'IN', 'values' => 1]],
            'nested array in IN' => ['status', ['mode' => 'IN', 'values' => [[1], 2]]],
            'scalar in BETWEEN' => ['date', ['mode' => 'BETWEEN', 'values' => 1]],
            'three values in BETWEEN' => ['date', ['mode' => 'BETWEEN', 'values' => [1, 2, 3]]],
            'non-string table' => ['status', ['mode' => 'EQUAL', 'table' => ['x'], 'values' => 1]],
            'non-string field' => ['x', ['mode' => 'EQUAL', 'field' => ['status'], 'values' => 1]],
        ];
    }

    #[DataProvider('droppedFilters')]
    public function testUnsafeFilterIsDroppedAndReported(string $key, array $filter): void
    {
        $result = FilteredDailyPartModel::_filtersql($key, $filter);

        $this->assertSame(['sql' => '', 'params' => []], $result);
        $this->assertCount(1, $this->violations);
    }

    public static function generatedFilters(): array
    {
        return [
            'GTE through field' => ['date1', ['mode' => 'GTE', 'field' => 'date', 'values' => 20260101], ' AND dailyparts.date>=? ', [20260101]],
            'IN on another table' => ['x', ['mode' => 'IN', 'field' => 'stateid', 'table' => 'pages', 'values' => [1, 2]], ' AND pages.stateid IN (?,?) ', [1, 2]],
            'key wins over injected field' => ['status', ['mode' => 'EQUAL', 'field' => 'status OR 1=1 --', 'values' => 1], ' AND dailyparts.status=? ', [1]],
            'plain list' => ['status', [1, 2], ' AND dailyparts.status IN (?,?) ', [1, 2]],
            'plain list with non-0-based keys' => ['status', [5 => 1, 9 => 2], ' AND dailyparts.status IN (?,?) ', [1, 2]],
            'IN with non-0-based keys' => ['status', ['mode' => 'IN', 'values' => [5 => 1, 9 => 2]], ' AND dailyparts.status IN (?,?) ', [1, 2]],
            'plain scalar' => ['status', 1, ' AND dailyparts.status=? ', [1]],
            'empty IN' => ['status', ['mode' => 'IN', 'values' => []], ' AND 1=0 ', []],
            'NOT_EQUAL zero' => ['status', ['mode' => 'NOT_EQUAL', 'values' => 0], ' AND dailyparts.status<>? ', [0]],
            'EQUAL' => ['status', ['mode' => 'EQUAL', 'values' => 3], ' AND dailyparts.status=? ', [3]],
            'GT' => ['status', ['mode' => 'GT', 'values' => 3], ' AND dailyparts.status>? ', [3]],
            'LT' => ['status', ['mode' => 'LT', 'values' => 3], ' AND dailyparts.status<? ', [3]],
            'LTE' => ['status', ['mode' => 'LTE', 'values' => 3], ' AND dailyparts.status<=? ', [3]],
            'LIKE' => ['status', ['mode' => 'LIKE', 'values' => 'ab'], ' AND dailyparts.status LIKE ? ', ['%ab%']],
            'BETWEEN' => ['date', ['mode' => 'BETWEEN', 'values' => [1, 2]], ' AND dailyparts.date>=? AND dailyparts.date<=? ', [1, 2]],
            'empty table falls back to main table' => ['status', ['mode' => 'EQUAL', 'table' => '', 'values' => 1], ' AND dailyparts.status=? ', [1]],
            'explicit main table' => ['status', ['mode' => 'EQUAL', 'table' => 'dailyparts', 'values' => 1], ' AND dailyparts.status=? ', [1]],
        ];
    }

    #[DataProvider('generatedFilters')]
    public function testSafeFilterGeneratesParameterizedSql(string $key, $filter, string $sql, array $params): void
    {
        $result = FilteredDailyPartModel::_filtersql($key, $filter);

        $this->assertSame(['sql' => $sql, 'params' => $params], $result);
        $this->assertSame([], $this->violations);
    }

    public static function ignoredFilters(): array
    {
        return [
            'unknown key with scalar' => ['foo', 1],
            'column with empty string' => ['status', ''],
            'integer key' => [0, 1],
            'integer key with list' => [0, [1, 2]],
        ];
    }

    #[DataProvider('ignoredFilters')]
    public function testIgnoredFilterProducesNoSql($key, $value): void
    {
        $result = FilteredDailyPartModel::_filtersql($key, $value);

        $this->assertSame(['sql' => '', 'params' => []], $result);
    }

    public function testExistcolRejectsNonStringColumns(): void
    {
        $this->assertTrue(FilteredDailyPartModel::existcol('status'));
        $this->assertFalse(FilteredDailyPartModel::existcol(0));
        $this->assertFalse(FilteredDailyPartModel::existcol(null));
        $this->assertFalse(FilteredDailyPartModel::existcol('nope'));
    }

    public function testExistcolDoesNotWarnWhenTableIsMissingFromSchema(): void
    {
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings) {
            $warnings[] = $errstr;
            return true;
        });
        try {
            $exists = FilteredMissingTableModel::existcol('status');
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($exists);
        $this->assertSame([], $warnings);
    }

    public function testViolationFallsBackToErrorLogWithoutHandler(): void
    {
        ModelBase::$onviolation = null;
        $logfile = tempnam(sys_get_temp_dir(), 'violation');
        $previous = ini_set('error_log', $logfile);
        try {
            $result = FilteredDailyPartModel::_filtersql('status', ['mode' => 'FOO', 'values' => 1]);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $logged = (string) file_get_contents($logfile);
        @unlink($logfile);

        $this->assertSame(['sql' => '', 'params' => []], $result);
        $this->assertStringContainsString('[VALIDATE] dailyparts: filter "status" dropped: unknown mode', $logged);
    }
}
