<?php
/**
 * Image normalisation for signage playback.
 *
 * Two problems this solves:
 *
 * 1. Cheap Android TV box WebViews (e.g. Droidlogic W2) silently fail to
 *    render images whose decoded bitmap exceeds their per-process memory
 *    budget. A 20 MB DSLR JPEG decodes to ~200 MB of RGBA and the <img>
 *    stays blank with no onerror fired. The JS duration timer keeps
 *    ticking, so the dashboard looks healthy while the screen is black.
 *
 * 2. Variable upload resolutions (1080p phone, 4K phone, screenshots,
 *    DSLR 24 MP) make playback unpredictable — even moderately sized
 *    images can blow decode budget on the weakest hardware.
 *
 * Fix: every image lands on disk at EXACTLY the player target resolution
 * (1920x1080 by default), letterboxed onto a black canvas to preserve
 * aspect ratio, recompressed at JPEG q85. Decode footprint becomes a
 * fixed ~24 MB RGBA — well within any modern WebView's budget.
 */

declare(strict_types=1);

if (!function_exists('image_normalize_for_signage')) {

    /**
     * Resize + letterbox + recompress an image in place.
     *
     * Output is always a JPEG of EXACTLY ($targetW x $targetH) pixels,
     * regardless of source format. Source aspect ratio is preserved by
     * scaling-to-fit and padding the unused area with $padColor.
     *
     * @param string $path        Absolute path on disk; will be overwritten.
     *                            If the source is PNG/WebP/etc the file extension
     *                            is preserved on disk, but the bytes inside become JPEG.
     * @param string $mime        Detected MIME type (image/jpeg, image/png, image/webp).
     * @param int    $targetW     Exact output width (default 1920).
     * @param int    $targetH     Exact output height (default 1080).
     * @param int    $jpegQuality 1-100; 85 is visually transparent on a TV.
     * @param array  $padColor    [R, G, B] for letterbox padding (default black).
     * @return bool true if the file was rewritten, false if GD missing or unsupported source.
     */
    function image_normalize_for_signage(
        string $path,
        string $mime,
        int $targetW = 1920,
        int $targetH = 1080,
        int $jpegQuality = 85,
        array $padColor = [0, 0, 0]
    ): bool {
        if (!function_exists('imagecreatefromjpeg')) return false; // GD not built in

        $mime = strtolower($mime);
        $src = null;
        switch ($mime) {
            case 'image/jpeg':
            case 'image/jpg':
                $src = @imagecreatefromjpeg($path);
                break;
            case 'image/png':
                $src = @imagecreatefrompng($path);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $src = @imagecreatefromwebp($path);
                }
                break;
            default:
                return false; // GIF / BMP / etc — leave alone
        }
        if (!$src) return false;

        $sw = imagesx($src);
        $sh = imagesy($src);

        // Scale-to-fit inside target box, preserve aspect ratio.
        $scale = min($targetW / $sw, $targetH / $sh);
        $iw = max(1, (int)round($sw * $scale));
        $ih = max(1, (int)round($sh * $scale));
        $ix = (int)(($targetW - $iw) / 2);
        $iy = (int)(($targetH - $ih) / 2);

        // Fast path: if source is already exactly target size AND already
        // a small JPEG, skip the lossy re-encode entirely.
        if ($sw === $targetW && $sh === $targetH
            && in_array($mime, ['image/jpeg', 'image/jpg'], true)
            && (int)@filesize($path) <= 800_000) {
            imagedestroy($src);
            return false;
        }

        $dst = imagecreatetruecolor($targetW, $targetH);
        $bg = imagecolorallocate($dst, $padColor[0] & 0xFF, $padColor[1] & 0xFF, $padColor[2] & 0xFF);
        imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $bg);

        imagecopyresampled($dst, $src, $ix, $iy, 0, 0, $iw, $ih, $sw, $sh);

        $tmpOut = $path . '.opt.tmp';
        $ok = imagejpeg($dst, $tmpOut, $jpegQuality);

        imagedestroy($src);
        imagedestroy($dst);

        if (!$ok) { @unlink($tmpOut); return false; }
        return @rename($tmpOut, $path);
    }

    /**
     * Backwards-compatible alias for callers that used the older "fit-only"
     * helper. Forwards to the new exact-resolution normaliser.
     */
    function image_optimize_for_signage(
        string $path,
        string $mime,
        int $maxWidth = 1920,
        int $maxHeight = 1080,
        int $jpegQuality = 85
    ): bool {
        return image_normalize_for_signage($path, $mime, $maxWidth, $maxHeight, $jpegQuality);
    }
}
