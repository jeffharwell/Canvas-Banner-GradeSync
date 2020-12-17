<?php declare(strict_types=1);
include './src/grading_scheme.php';
use PHPUnit\Framework\TestCase;


final class GradingSchemeTest extends TestCase
{
    public function testValidateExportReturnsTrueForValidExport(): void
    {
        $raw_grades = file_get_contents("./tests/resources/sample_grades_duplicate_user_line.csv");
        $raw_grade_lines = explode("\n", $raw_grades);
        $headers = explode(",",array_slice($raw_grade_lines, 0, 1)[0]);
        $grade_lines = array_slice($raw_grade_lines, 1);

        $r = validate_export($grade_lines, false);
        $this->assertTrue($r);
    }
    public function testThrowsRuntimeExceptionWhenMissingGnumberForCanvasId(): void
    {
        $raw_grades = file_get_contents("./tests/resources/sample_grades_missing_gnumber.csv");
        $raw_grade_lines = explode("\n", $raw_grades);
        $headers = explode(",",array_slice($raw_grade_lines, 0, 1)[0]);
        $grade_lines = array_slice($raw_grade_lines, 1);

        $this->expectException(RuntimeException::class);
        validate_export($grade_lines, false);
    }
    public function testTesting(): void
    {
        $raw_grades = file_get_contents("./tests/resources/sample_grades_duplicate_user_line.csv");
        $raw_grade_lines = explode("\n", $raw_grades);
        $headers = explode(",",array_slice($raw_grade_lines, 0, 1)[0]);
        $grade_lines = array_slice($raw_grade_lines, 1);

        $this->assertTrue(true);
    }
}

