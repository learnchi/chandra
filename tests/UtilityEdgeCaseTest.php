<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Support\Utility;

require_once __DIR__ . '/../vendor/autoload.php';

final class UtilityEdgeCaseTest extends TestCase
{
    /**
     * 目的: プレースホルダ置換が数値キーと連想キーの両方で動作することを確認する。
     * 期待: {0} と {name} が正しく置換される。
     */
    public function testReplacePlaceholdersWithNumericAndNamedKeys(): void
    {
        $this->assertSame('A-B', Utility::replacePlaceholders('{0}-{1}', 'A', 'B'));
        $this->assertSame('Hello Alice', Utility::replacePlaceholders('Hello {name}', ['{name}' => 'Alice']));
    }

    /**
     * 目的: checkNumeric が境界値を含めて判定することを確認する。
     * 期待: 下限=上限の値は true、範囲外は false になる。
     */
    public function testCheckNumericBoundary(): void
    {
        $this->assertTrue(Utility::checkNumeric('5', 5, 5));
        $this->assertFalse(Utility::checkNumeric('4', 5, 5));
    }

    /**
     * 目的: 画像サイズの上限を引数で指定できることを確認する。
     * 期待: 指定した上限以内は true、超過は false になる。
     */
    public function testCheckImageFileSizeUsesCustomLimit(): void
    {
        $this->assertTrue(Utility::checkImageFileSize(1000, 1000));
        $this->assertFalse(Utility::checkImageFileSize(1001, 1000));
    }

    /**
     * 目的: checkAlphanumeric が片側境界のみ指定された場合は false を返すことを確認する。
     * 期待: 片側のみの指定では false になる。
     */
    public function testCheckAlphanumericReturnsFalseWhenOnlyOneBoundProvided(): void
    {
        $this->assertFalse(Utility::checkAlphanumeric('abc', 1, null));
        $this->assertFalse(Utility::checkAlphanumeric('abc', null, 3));
    }

    /**
     * 目的: 画像ファイル名にパス区切りが含まれる場合は拒否されることを確認する。
     * 期待: パス区切りを含む場合は false になる。
     */
    public function testCheckImageNameRejectsPathSeparators(): void
    {
        $this->assertFalse(Utility::checkImageName('dir/sample.png'));
        $this->assertFalse(Utility::checkImageName('dir\\sample.png'));
    }

    /**
     * 目的: ディレクトリが存在しない場合に空配列を返すことを確認する。
     * 期待: getFileName は空配列を返す。
     */
    public function testGetFileNameReturnsEmptyWhenDirectoryMissing(): void
    {
        $this->assertSame([], Utility::getFileName('Z:/path/does/not/exist'));
    }
}

