<?php

declare(strict_types=1);

namespace Kaly\Util;

use RuntimeException;

/**
 * @link https://github.com/nette/utils/blob/master/src/Utils/Image.php
 */
final class Img
{
    public static function resize(
        string $src,
        ?string $dst = null,
        int $width = 0,
        int $height = 0,
        bool $crop = false
    ): bool {
        $type = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        if ($type === 'jpeg') {
            $type = 'jpg';
        }

        $size = getimagesize($src);
        $w = $size[0] ?? 0;
        $h = $size[1] ?? 0;
        if ($w <= 0 || $h <= 0) {
            throw new RuntimeException("Unsupported picture type: `$type`!");
        }

        $width = $width > 0 ? $width : $w;
        $height = $height > 0 ?  $height : $h;

        $img = match ($type) {
            'bmp' => imagecreatefromwbmp($src),
            'gif' => imagecreatefromgif($src),
            'jpg' => imagecreatefromjpeg($src),
            'png' => imagecreatefrompng($src),
            'webp' => imagecreatefromwebp($src),
            default => throw new RuntimeException("Unsupported picture type: `$type`!"),
        };
        if (!$img) {
            return false;
        }

        $intround = function ($v) {
            return intval(round($v));
        };

        // resize if needed
        if ($crop) {
            if ($w < $width || $h < $height) {
                return false;
            }
            $ratio = max($width / $w, $height / $h);
            $x = $intround(($w - $width / $ratio) / 2);
            $y = $intround(($h - $height / $ratio) / 2);
            $h = $intround($height / $ratio);
            $w = $intround($width / $ratio);
        } else {
            if ($w < $width && $h < $height) {
                return false;
            }
            $ratio = min($width / $w, $height / $h);
            $x = 0;
            $y = 0;
            $width = $intround($w * $ratio);
            $height = $intround($h * $ratio);
        }

        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $new = imagecreatetruecolor($width, $height);

        // preserve transparency
        if (in_array($type, ['gif', 'png', 'webp'])) {
            imagealphablending($new, false);
            imagesavealpha($new, true);
        }

        imagecopyresampled($new, $img, 0, 0, $x, $y, $width, $height, $w, $h);

        $dst ??= $src;

        $res = match ($type) {
            'bmp' => imagewbmp($new, $dst),
            'gif' => imagegif($new, $dst),
            'jpg' => imagejpeg($new, $dst),
            'png' => imagepng($new, $dst),
            'webp' => imagewebp($new, $dst),
            default => throw new RuntimeException("Unsupported picture type: `$type`!"),
        };
        return $res;
    }

    public static function toBase64(string $filename, int $width = 0): string
    {
        $contents = file_get_contents($filename);
        if (!$contents) {
            throw new RuntimeException("File $filename is empty");
        }
        if ($width > 0) {
            $temp = tmpfile();
            fwrite($temp, $contents);
            self::resize($filename, null, $width);
            fseek($temp, 0);
            $contents = stream_get_contents($temp);
            fclose($temp);
            if (!$contents) {
                throw new RuntimeException("Resized file $filename is empty");
            }
        }
        $mime = mime_content_type($filename);
        $src = "data:$mime;base64," . str_replace("\n", "", base64_encode($contents));
        return $src;
    }
}
