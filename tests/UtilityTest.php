<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Support\Utility;

require_once __DIR__ . '/../vendor/autoload.php';

final class UtilityTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'utility-' . uniqid();
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->workDir . DIRECTORY_SEPARATOR . '*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            } elseif (is_dir($file)) {
                @rmdir($file);
            }
        }
        @rmdir($this->workDir);
        parent::tearDown();
    }

    /**
     * replaceStr / replacePlaceholders がプレースホルダーを正しく置換することを確認する。
     */
    public function testReplaceStrAndPlaceholders(): void
    {
        $this->assertSame('Hello World !', Utility::replaceStr('Hello {0} {1}', 'World', '!'));
        $this->assertSame('ID:42', Utility::replacePlaceholders('ID:{id}', ['{id}' => 42]));
        $this->assertSame('No change', Utility::replacePlaceholders('No change'));
        $this->assertNull(Utility::replaceStr(null));
    }

    /**
     * 画像ファイル名チェッカーが許容拡張子と文字だけを通すことを確認する。
     */
    public function testCheckImageName(): void
    {
        $this->assertTrue(Utility::checkImageName('sample-01.png'));
        $this->assertTrue(Utility::checkImageName('photo.JPG'));
        $this->assertFalse(Utility::checkImageName('bad name.png'));
        $this->assertFalse(Utility::checkImageName('double..dot.jpg'));
    }

    /**
     * 数値チェッカーが数値かつ範囲内かを判定することを確認する。
     */
    public function testCheckNumeric(): void
    {
        $this->assertTrue(Utility::checkNumeric('10', 5, 10));
        $this->assertFalse(Utility::checkNumeric('11', 5, 10));
        $this->assertFalse(Utility::checkNumeric('abc'));
    }

    /**
     * 英数字チェッカーが許可文字と長さの範囲を評価することを確認する。
     */
    public function testCheckAlphanumeric(): void
    {
        $this->assertTrue(Utility::checkAlphanumeric('abc-123', 3, 10));
        $this->assertFalse(Utility::checkAlphanumeric('abc@123', 3, 10));
        $this->assertFalse(Utility::checkAlphanumeric('abcd', 2, null));
    }

    /**
     * 重複チェッカーが指定キーの重複だけを返すことを確認する。
     */
    public function testCheckDuplicate(): void
    {
        $data = [
            ['code' => 'A01'],
            ['code' => 'B02'],
            ['code' => 'A01'],
            ['code' => ''],
        ];

        $duplicates = Utility::checkDuplicate($data, 'code');

        $this->assertSame(['A01'], $duplicates);
    }

    /**
     * 画像サイズチェッカーが 2MB 超で false を返すことを確認する。
     */
    public function testCheckImageFileSize(): void
    {
        $this->assertTrue(Utility::checkImageFileSize(1_500_000));
        $this->assertFalse(Utility::checkImageFileSize(2_500_000));
    }

    /**
     * getFileName がフラグごとにファイル/ディレクトリ名を返すことを確認する。
     */
    public function testGetFileName(): void
    {
        $filePath = $this->workDir . DIRECTORY_SEPARATOR . 'file.txt';
        $dirPath = $this->workDir . DIRECTORY_SEPARATOR . 'child';
        file_put_contents($filePath, 'dummy');
        mkdir($dirPath);

        $this->assertSame(['file.txt'], Utility::getFileName($this->workDir, 0));
        $this->assertSame(['child'], Utility::getFileName($this->workDir, 1));

        $all = Utility::getFileName($this->workDir, 2);
        sort($all);
        $this->assertSame(['child', 'file.txt'], $all);
    }

    /**
     * GD 有効時にサムネイルが生成されることを確認する。
     */
    public function testCheckImageCreateAndThumbnail(): void
    {
        $this->requireGd();

        $source = $this->workDir . DIRECTORY_SEPARATOR . 'src.png';
        $thumb = $this->workDir . DIRECTORY_SEPARATOR . 's_src.png';
        $this->createPng($source, 120, 90);

        $result = Utility::checkImageCreate($this->workDir, 'src.png', 60);

        $this->assertTrue($result);
        $this->assertFileExists($thumb);
        [$width] = getimagesize($thumb);
        $this->assertSame(60, $width);
    }

    /**
     * creatImageSize が指定幅の画像を生成することを確認する。
     */
    public function testCreateImageSize(): void
    {
        $this->requireGd();

        $source = $this->workDir . DIRECTORY_SEPARATOR . 'original.jpg';
        $resized = $this->workDir . DIRECTORY_SEPARATOR . 'resized.jpg';
        $this->createJpeg($source, 80, 40);

        $ok = Utility::creatImageSize($source, $resized, 50);

        $this->assertTrue($ok);
        [$width] = getimagesize($resized);
        $this->assertSame(50, $width);
    }

    /**
     * creatImageSize34 が縦長画像を 4:3 内に収めるようリサイズ・トリミングすることを確認する。
     */
    public function testCreateImageSize34CropsTallImage(): void
    {
        $this->requireGd();

        $source = $this->workDir . DIRECTORY_SEPARATOR . 'tall.gif';
        $resized = $this->workDir . DIRECTORY_SEPARATOR . 'tall_out.gif';
        $this->createGif($source, 40, 200);

        $ok = Utility::creatImageSize34($source, $resized, 20);

        $this->assertTrue($ok);
        [$width, $height] = getimagesize($resized);
        $this->assertSame(20, $width);
        $this->assertSame(15, $height);
    }

    private function requireGd(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for image tests.');
        }
    }

    private function createPng(string $path, int $width, int $height): void
    {
        $img = imagecreatetruecolor($width, $height);
        imagepng($img, $path);
        imagedestroy($img);
    }

    private function createJpeg(string $path, int $width, int $height): void
    {
        $img = imagecreatetruecolor($width, $height);
        imagejpeg($img, $path);
        imagedestroy($img);
    }

    private function createGif(string $path, int $width, int $height): void
    {
        $img = imagecreatetruecolor($width, $height);
        imagegif($img, $path);
        imagedestroy($img);
    }
}
