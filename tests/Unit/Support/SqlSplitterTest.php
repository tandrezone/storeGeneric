<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\SqlSplitter;
use Tests\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function testSplitsOnSemicolonsOutsideStrings(): void
    {
        $sql = "-- header comment\nSET NAMES utf8mb4;\n"
            . "INSERT INTO t (a) VALUES ('x;y'), ('it''s; ok'), ('back\\'slash;');\n"
            . "CREATE TABLE `we;ird` (\n  id INT COMMENT 'a; b;'\n);\n";

        $this->assertSame([
            'SET NAMES utf8mb4',
            "INSERT INTO t (a) VALUES ('x;y'), ('it''s; ok'), ('back\\'slash;')",
            "CREATE TABLE `we;ird` (\n  id INT COMMENT 'a; b;'\n)",
        ], SqlSplitter::split($sql));
    }

    public function testMultiLineStringValues(): void
    {
        $sql = "INSERT INTO t VALUES ('line 1;\nline 2;\n');\nSELECT 1;\n";
        $this->assertSame(["INSERT INTO t VALUES ('line 1;\nline 2;\n')", 'SELECT 1'], SqlSplitter::split($sql));
    }

    public function testBlockAndConditionalComments(): void
    {
        $sql = "/* a; comment */\nSELECT 1;\n/*!40101 SET NAMES utf8mb4 */;\n";
        $this->assertSame(["/* a; comment */\nSELECT 1", '/*!40101 SET NAMES utf8mb4 */'], SqlSplitter::split($sql));
    }

    public function testLastStatementWithoutSemicolonAndCommentOnlyInput(): void
    {
        $this->assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::split("SELECT 1;\nSELECT 2"));
        $this->assertSame([], SqlSplitter::split("-- nothing here\n\n"));
    }
}
