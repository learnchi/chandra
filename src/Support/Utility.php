<?php

namespace Studiogau\Chandra\Support;

use Studiogau\Chandra\Config\ChandraConst;

/**
 * フレームワーク共通で使い回す小さなヘルパー群。
 */
final class Utility
{
    /**
     * インスタンス化させない。
     */
    private function __construct()
    {
    }

    /**
     * 置換ラッパー。
     *
     * @param string $template      置換対象の文字列
     * @param mixed  ...$replacements 差し込み値
     * @return string|null
     */
    public static function replaceStr($template, ...$replacements)
    {
        if ($template === null) {
            return null;
        }

        return self::replacePlaceholders($template, ...$replacements);
    }

    /**
     * {0}, {foo} 形式のプレースホルダーを指定値で置換する。
     *
     * 可変長引数または連想配列のどちらでも指定可能。
     * 数値キーは自動的に {index} プレースホルダーへ変換される。
     *
     * @param string $template      置換対象の文字列
     * @param mixed  ...$replacements 差し込み値
     * @return string
     */
    public static function replacePlaceholders($template, ...$replacements)
    {
        if (!is_string($template) || $template === '') {
            return (string) $template;
        }

        if (count($replacements) === 1 && is_array($replacements[0])) {
            $replacements = $replacements[0];
        }

        $map = array();
        foreach ($replacements as $key => $value) {
            if (is_string($key)) {
                $map[$key] = (string) $value;
            } else {
                $placeholder = '{' . $key . '}';
                $map[$placeholder] = (string) $value;
            }
        }

        if (empty($map)) {
            return $template;
        }

        return strtr($template, $map);
    }

    /**
     * CSRFトークン用 hidden input の name 属性を返す。
     *
     * @return string
     */
    public static function getCsrfFieldName(): string
    {
        return '_csrf_token';
    }

    /**
     * CSRFトークン用 scope hidden input の name 属性を返す。
     *
     * @return string
     */
    public static function getCsrfScopeFieldName(): string
    {
        return '_csrf_scope';
    }

    /**
     * 指定スコープ用の CSRF トークンを発行してセッションに保存する。
     *
     * @param string $scope 画面や操作単位の識別子
     * @return string
     */
    public static function issueCsrfToken(string $scope): string
    {
        $tokens = self::getCsrfTokenMap();
        if (isset($tokens[$scope]) && is_string($tokens[$scope]) && $tokens[$scope] !== '') {
            return $tokens[$scope];
        }

        $token = bin2hex(random_bytes(32));
        $tokens[$scope] = $token;
        $_SESSION[self::csrfSessionKey()] = $tokens;

        return $token;
    }

    /**
     * CSRF トークンを hidden input として出力する HTML を返す。
     *
     * @param string $scope 画面や操作単位の識別子
     * @return string
     */
    public static function renderCsrfHiddenInput(string $scope): string
    {
        $scopeName = htmlspecialchars(self::getCsrfScopeFieldName(), ENT_QUOTES, 'UTF-8');
        $scopeValue = htmlspecialchars($scope, ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars(self::getCsrfFieldName(), ENT_QUOTES, 'UTF-8');
        $value = htmlspecialchars(self::issueCsrfToken($scope), ENT_QUOTES, 'UTF-8');

        return '<input type="hidden" name="' . $scopeName . '" value="' . $scopeValue . '">'
            . '<input type="hidden" name="' . $name . '" value="' . $value . '">';
    }

    /**
     * AJAX 用の CSRF hidden input を包むコンテナ HTML を返す。
     *
     * @param string $scope AJAX 通信で使う CSRF scope
     * @param string $containerId コンテナ要素の id 属性
     * @param string $class コンテナ要素の class 属性
     * @return string
     */
    public static function renderCsrfContainer(string $scope, string $containerId, string $class = 'd-none'): string
    {
        $id = htmlspecialchars($containerId, ENT_QUOTES, 'UTF-8');
        $classAttr = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');

        return '<div id="' . $id . '" class="' . $classAttr . '">'
            . self::renderCsrfHiddenInput($scope)
            . '</div>';
    }

    /**
     * AJAX POST や JSON 応答に載せやすい CSRF 2項目を配列で返す。
     *
     * @param string $scope AJAX 通信で使う CSRF scope
     * @return array<string, string>
     */
    public static function issueCsrfPostFields(string $scope): array
    {
        return [
            self::getCsrfScopeFieldName() => $scope,
            self::getCsrfFieldName() => self::issueCsrfToken($scope),
        ];
    }

    /**
     * 指定スコープの CSRF トークンを検証する。トークンは検証後に破棄する。
     *
     * @param string      $scope          画面や操作単位の識別子
     * @param string|null $submittedToken POST されたトークン
     * @return bool
     */
    public static function validateCsrfToken(string $scope, ?string $submittedToken): bool
    {
        $tokens = self::getCsrfTokenMap();
        $storedToken = $tokens[$scope] ?? null;

        unset($tokens[$scope]);
        $_SESSION[self::csrfSessionKey()] = $tokens;

        if (!is_string($storedToken) || $storedToken === '') {
            return false;
        }

        if (!is_string($submittedToken) || $submittedToken === '') {
            return false;
        }

        return hash_equals($storedToken, $submittedToken);
    }

    /**
     * POST された scope と token の組み合わせで CSRF トークンを検証する。
     *
     * @param string|null $submittedScope POST された scope
     * @param string|null $submittedToken POST された token
     * @return bool
     */
    public static function validatePostedCsrfToken(?string $submittedScope, ?string $submittedToken): bool
    {
        if (!is_string($submittedScope) || $submittedScope === '') {
            return false;
        }

        return self::validateCsrfToken($submittedScope, $submittedToken);
    }

    /**
     * 画像ファイル名の形式をチェックする。
     *
     * @param string $str 画像ファイル名
     * @return bool チェックOKならtrue
     */
    public static function checkImageName($str)
    {
        $rtn = false;
        if (!empty($str)) {
            if (preg_match('/^[0-9a-zA-Z_.\\-]+$/', $str)) {
                $filename = explode('.', $str);
                if (count($filename) === 2) {
                    if (strcasecmp($filename[1], 'jpg') === 0
                        || strcasecmp($filename[1], 'png') === 0
                        || strcasecmp($filename[1], 'gif') === 0) {
                        $rtn = true;
                    }
                }
            }
        }
        return $rtn;
    }

    /**
     * 数値かどうかをチェックする（範囲指定も可）。
     *
     * @param string   $str  入力値
     * @param int|null $from 許容最小値（任意）
     * @param int|null $to   許容最大値（任意）
     * @return bool チェックOKならtrue
     */
    public static function checkNumeric($str, $from = null, $to = null)
    {
        if (!is_numeric($str)) {
            return false;
        }
        if ($from !== null && $to !== null) {
            return ($str >= $from && $str <= $to);
        }
        return true;
    }

    /**
     * 半角英数字かどうかをチェックする（長さ範囲も指定可）。
     * 片側のみの範囲指定の場合は false を返す。
     *
     * @param string   $str  入力値
     * @param int|null $from 許容最小文字数（任意）
     * @param int|null $to   許容最大文字数（任意）
     * @return bool チェックOKならtrue
     */
    public static function checkAlphanumeric(string $str, $from = null, $to = null): bool
    {
        if (!preg_match('/^[0-9a-zA-Z\\-]+$/', $str)) {
            return false;
        }

        if ($from === null && $to === null) {
            return true;
        }

        if ($from !== null && $to !== null) {
            $len = strlen($str);
            return ($len >= $from && $len <= $to);
        }

        return false;
    }

    /**
     * 指定キーの値が重複しているかを調べる。
     *
     * @param array  $dataList 一覧データ
     * @param string $key      チェックするキー
     * @return array 重複していた値の配列（重複なしなら空配列）
     */
    public static function checkDuplicate(array $dataList, string $key): array
    {
        $vals = array_column($dataList, $key);

        $vals = array_filter($vals, fn($v) => $v !== null && $v !== '');
        if ($vals === []) {
            return [];
        }

        $counts = array_count_values(array_map('strval', $vals));
        return array_keys(array_filter($counts, fn($c) => $c >= 2));
    }

    /**
     * 画像ファイルサイズをチェックする。
     * ChandraConst::MAX_UPLOAD_IMAGE_SIZE を上限として扱う。
     *
     * @param int      $fileSize バイト数
     * @param int|null $maxBytes 最大サイズ（バイト）。指定時はこちらを優先する。
     * @return bool チェックOKならtrue
     */
    public static function checkImageFileSize($fileSize, ?int $maxBytes = null)
    {
        $limit = $maxBytes ?? ChandraConst::MAX_UPLOAD_IMAGE_SIZE;
        return $fileSize <= $limit;
    }

    /**
     * 画像ファイルをチェックし、サムネイルを生成する。
     *
     * @param string $dirnm ディレクトリ名
     * @param string $filenm ファイル名
     * @param int    $tsize サムネイル横幅
     * @return bool true:生成成功 / false:失敗
     */
    public static function checkImageCreate($dirnm, $filenm, $tsize)
    {
        $rtn = false;
        if (!empty($filenm)) {
            if (file_exists($dirnm . '/' . $filenm)) {
                $rtn = true;
                if (!file_exists($dirnm . '/s_' . $filenm)) {
                    $rtn = self::creatSmallImage($dirnm, $filenm, $tsize);
                }
            }
        }

        return $rtn;
    }

    /**
     * サムネイル画像を生成する。
     *
     * @param string $dirnm  ディレクトリ名
     * @param string $filenm ファイル名
     * @param int    $tsize  サムネイル横幅
     * @return bool true:生成成功 / false:失敗
     */
    public static function creatSmallImage($dirnm, $filenm, $tsize)
    {
        $targetFile = $dirnm . '/' . $filenm;
        $sfile_name = $dirnm . '/s_' . $filenm;
        return self::creatImageSize34($targetFile, $sfile_name, $tsize);
    }

    /**
     * 指定サイズにリサイズした画像を生成する。
     *
     * @param string $targetFile  元ファイル名
     * @param string $createFile  作成ファイル名
     * @param int    $imgSize     サムネイル横幅
     * @return bool true:生成成功 / false:失敗
     */
    public static function creatImageSize($targetFile, $createFile, $imgSize)
    {
        $rtn = false;

        if ($targetFile != null && $targetFile != "" && file_exists($targetFile)) {
            $file_size = @getimagesize($targetFile);
            $file_x = $file_size[0];    // 横幅
            $file_y = $file_size[1];    // 高さ
            $sfile_y = $file_y;
            if ($file_x !== $imgSize) {
                $sfile_y = round($file_y * $imgSize / $file_x);
            }

            if (preg_match('/.jpg/i', $targetFile) || preg_match('/.jpeg/i', $targetFile)) {
                $file_img = @imagecreatefromjpeg($targetFile);
            } else if (preg_match('/.png/i', $targetFile)) {
                $file_img = @imagecreatefrompng($targetFile);
            } else if (preg_match('/.gif/i', $targetFile)) {
                $file_img = @imagecreatefromgif($targetFile);
            }

            $sfile_img = imagecreatetruecolor($imgSize, $sfile_y);
            imagecopyresampled($sfile_img, $file_img, 0, 0, 0, 0, $imgSize, $sfile_y, $file_x, $file_y);
            imagedestroy($file_img);

            if (preg_match('/.jpg/i', $targetFile) || preg_match('/.jpeg/i', $targetFile)) {
                $rtn = @imagejpeg($sfile_img, $createFile);
            } else if (preg_match('/.png/i', $targetFile)) {
                $rtn = @imagepng($sfile_img, $createFile);
            } else if (preg_match('/.gif/i', $targetFile)) {
                $rtn = @imagegif($sfile_img, $createFile);
            }

            imagedestroy($sfile_img);
        }

        return $rtn;
    }

    /**
     * 元画像を4:3を上限とする比率で調整し、必要に応じて中央トリミングする。
     *
     * @param string $targetFile 元ファイル名
     * @param string $createFile 作成ファイル名
     * @param int    $width      出力画像の横幅
     * @return bool true:生成成功 / false:失敗
     */
    public static function creatImageSize34($targetFile, $createFile, $width)
    {
        if (!is_file($targetFile)) {
            return false;
        }

        $info = @getimagesize($targetFile);
        if ($info === false) {
            return false;
        }

        [$file_x, $file_y] = $info;
        $max_h = round($width * 3 / 4);

        if ($file_x <= $width) {
            $new_w = $file_x;
            $new_h = $file_y;
        } else {
            $new_w = $width;
            $new_h = round($file_y * $width / $file_x);
        }

        if (preg_match('/\\.jpe?g$/i', $targetFile)) {
            $src = @imagecreatefromjpeg($targetFile);
        } elseif (preg_match('/\\.png$/i', $targetFile)) {
            $src = @imagecreatefrompng($targetFile);
        } elseif (preg_match('/\\.gif$/i', $targetFile)) {
            $src = @imagecreatefromgif($targetFile);
        } else {
            return false;
        }

        $resized = imagecreatetruecolor($new_w, $new_h);
        imagecopyresampled(
            $resized,
            $src,
            0,
            0,
            0,
            0,
            $new_w,
            $new_h,
            $file_x,
            $file_y
        );
        imagedestroy($src);

        if ($new_h > $max_h) {
            $crop_y = round(($new_h - $max_h) / 2);
            $final = imagecreatetruecolor($new_w, $max_h);

            imagecopy(
                $final,
                $resized,
                0,
                0,
                0,
                $crop_y,
                $new_w,
                $max_h
            );
            imagedestroy($resized);
        } else {
            $final = $resized;
        }

        if (preg_match('/\\.jpe?g$/i', $createFile)) {
            $result = imagejpeg($final, $createFile, 90);
        } elseif (preg_match('/\\.png$/i', $createFile)) {
            $result = imagepng($final, $createFile);
        } elseif (preg_match('/\\.gif$/i', $createFile)) {
            $result = imagegif($final, $createFile);
        } else {
            $result = false;
        }

        imagedestroy($final);
        return $result;
    }

    /**
     * 指定ディレクトリ配下のファイル／ディレクトリ一覧を取得する。
     *
     * @param string $dir ディレクトリ名
     * @param int    $flg 0:ファイルのみ 1:ディレクトリのみ 2:両方（デフォルト）
     * @return array ファイル名の配列
     */
    public static function getFileName($dir, $flg = 0)
    {
        $fnames = [];

        if (!is_dir($dir)) {
            return $fnames;
        }

        $exclude = ['.', '..', '.htaccess'];
        foreach (scandir($dir) as $fileNm) {
            if (in_array($fileNm, $exclude, true)) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $fileNm;
            $isDir = is_dir($path);

            if ($flg === 0 && $isDir) {
                continue;
            }
            if ($flg === 1 && !$isDir) {
                continue;
            }

            $fnames[] = $fileNm;
        }
        return $fnames;
    }

    /**
     * @return array<string, string>
     */
    private static function getCsrfTokenMap(): array
    {
        $tokens = $_SESSION[self::csrfSessionKey()] ?? null;

        return is_array($tokens) ? $tokens : [];
    }

    private static function csrfSessionKey(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dirs = explode('/', $script);
        $project = $dirs[1] ?? '';

        return $project . 'csrf_tokens';
    }

    /**
     * =========================================
     * PRIVATE METHODS
     * =========================================
     */
}
