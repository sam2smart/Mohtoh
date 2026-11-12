<?php
// includes/image_optimizer.php
//
// Self-contained image optimization helpers for MohToh, shared by:
//   - img.php                    (public on-the-fly thumbnail endpoint)
//   - api/driver-api.php         (vehicle photo upload)
//   - api/profile.php            (profile photo upload)
//   - api/v1/files/upload.php    (token API: profile + vehicle photo)
//
// Contract / guarantees:
//   * NO top-level side effects (no session_start, no output) and safe to
//     include more than once — every constant/function is guard-defined.
//   * The serving path NEVER mutates or deletes an original upload. Derived
//     thumbnails live only under uploads/cache/.
//   * Verification documents are NEVER optimized in place — only vehicle and
//     profile photos are touched on upload, so document originals stay
//     byte-for-byte identical (verification integrity).
//   * Graceful degradation: if GD is missing, the file is not a resizable
//     raster, or anything at all fails, the ORIGINAL bytes are streamed, so
//     every existing image / URL keeps working exactly as before.

if (!defined('MT_IMG_UPLOADS_DIR'))    define('MT_IMG_UPLOADS_DIR', __DIR__ . '/../uploads/');
if (!defined('MT_IMG_CACHE_DIR'))      define('MT_IMG_CACHE_DIR', __DIR__ . '/../uploads/cache/');
if (!defined('MT_IMG_CACHE_VERSION'))  define('MT_IMG_CACHE_VERSION', 'v1');
if (!defined('MT_IMG_JPEG_QUALITY'))   define('MT_IMG_JPEG_QUALITY', 82);
if (!defined('MT_IMG_WEBP_QUALITY'))   define('MT_IMG_WEBP_QUALITY', 80);
if (!defined('MT_IMG_MAX_UPLOAD_DIM')) define('MT_IMG_MAX_UPLOAD_DIM', 1600);
if (!defined('MT_IMG_UPLOAD_QUALITY')) define('MT_IMG_UPLOAD_QUALITY', 85);
// Whitelisted thumbnail widths. This is also a security control: it bounds
// how many derivative files the cache can ever hold.
if (!defined('MT_IMG_ALLOWED_WIDTHS')) define('MT_IMG_ALLOWED_WIDTHS', [64, 160, 400, 800, 1600]);
// Raster formats we can decode/resize with GD.
if (!defined('MT_IMG_RASTER_EXT'))     define('MT_IMG_RASTER_EXT', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
// Formats we are willing to re-encode in place on upload (never gif).
if (!defined('MT_IMG_OPTIMIZE_EXT'))   define('MT_IMG_OPTIMIZE_EXT', ['jpg', 'jpeg', 'png', 'webp']);

if (!function_exists('mt_gd_available')) {
    function mt_gd_available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }
}

if (!function_exists('mt_img_ext_of')) {
    function mt_img_ext_of(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }
}

if (!function_exists('mt_img_mime_for_ext')) {
    // Content-Type is ALWAYS derived from a fixed map — never from user input.
    function mt_img_mime_for_ext(string $ext): string
    {
        switch ($ext) {
            case 'jpg':
            case 'jpeg': return 'image/jpeg';
            case 'png':  return 'image/png';
            case 'webp': return 'image/webp';
            case 'gif':  return 'image/gif';
            case 'bmp':  return 'image/bmp';
            case 'pdf':  return 'application/pdf';
            default:     return 'application/octet-stream';
        }
    }
}

if (!function_exists('mt_img_load')) {
    // Returns a GdImage on success, false on failure. Never throws.
    function mt_img_load(string $absPath, string $ext)
    {
        switch ($ext) {
            case 'jpg':
            case 'jpeg': return @imagecreatefromjpeg($absPath);
            case 'png':  return @imagecreatefrompng($absPath);
            case 'webp': return @imagecreatefromwebp($absPath);
            case 'gif':  return @imagecreatefromgif($absPath);
            default:     return false;
        }
    }
}

if (!function_exists('mt_img_rotate_free')) {
    // imagerotate() allocates a NEW image; free the old one to bound memory.
    function mt_img_rotate_free($img, int $degrees)
    {
        $rotated = @imagerotate($img, $degrees, 0);
        if ($rotated) {
            imagedestroy($img);
            return $rotated;
        }
        return $img;
    }
}

if (!function_exists('mt_img_apply_exif_orientation')) {
    // GD ignores EXIF, so a portrait phone photo would decode sideways and the
    // thumbnail would not match the browser-rotated original. Bake rotation
    // into the pixels. JPEG-only (the only format that carries EXIF here).
    function mt_img_apply_exif_orientation($img, string $absPath, string $ext)
    {
        if (($ext !== 'jpg' && $ext !== 'jpeg') || !function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($absPath);
        if (!$exif || empty($exif['Orientation'])) {
            return $img;
        }
        switch ((int) $exif['Orientation']) {
            case 2: imageflip($img, IMG_FLIP_HORIZONTAL); break;
            case 3: $img = mt_img_rotate_free($img, 180); break;
            case 4: imageflip($img, IMG_FLIP_VERTICAL); break;
            case 5: $img = mt_img_rotate_free($img, -90); imageflip($img, IMG_FLIP_HORIZONTAL); break;
            case 6: $img = mt_img_rotate_free($img, -90); break;
            case 7: $img = mt_img_rotate_free($img, 90); imageflip($img, IMG_FLIP_HORIZONTAL); break;
            case 8: $img = mt_img_rotate_free($img, 90); break;
        }
        return $img;
    }
}

if (!function_exists('mt_img_scaled_canvas')) {
    // Shared resample core. Returns $src unchanged when no downscale is needed
    // (never upscales). Preserves alpha for png/webp.
    function mt_img_scaled_canvas($src, int $targetW, int $targetH)
    {
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= 0 || $h <= 0 || ($targetW >= $w && $targetH >= $h)) {
            return $src;
        }
        $dst = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $transparent);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $targetW, $targetH, $w, $h);
        return $dst;
    }
}

if (!function_exists('mt_img_resize_width')) {
    // Constrain by WIDTH (thumbnail endpoint). Height follows aspect ratio.
    function mt_img_resize_width($src, int $targetW)
    {
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= 0 || $h <= 0 || $targetW >= $w) {
            return $src; // never upscale
        }
        $targetH = (int) max(1, round($h * ($targetW / $w)));
        return mt_img_scaled_canvas($src, $targetW, $targetH);
    }
}

if (!function_exists('mt_img_resize_longest')) {
    // Constrain the LONGEST side (upload-time optimize) so both portrait and
    // landscape phone photos are capped.
    function mt_img_resize_longest($src, int $maxDim)
    {
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w <= 0 || $h <= 0 || max($w, $h) <= $maxDim) {
            return $src;
        }
        if ($w >= $h) {
            $targetW = $maxDim;
            $targetH = (int) max(1, round($h * ($maxDim / $w)));
        } else {
            $targetH = $maxDim;
            $targetW = (int) max(1, round($w * ($maxDim / $h)));
        }
        return mt_img_scaled_canvas($src, $targetW, $targetH);
    }
}

if (!function_exists('mt_img_encode')) {
    // Encode $img to $destPath in $format. jpeg flattens transparency onto
    // white; png/webp keep alpha. Returns bool, never throws.
    function mt_img_encode($img, string $format, int $quality, string $destPath): bool
    {
        switch ($format) {
            case 'webp':
                return @imagewebp($img, $destPath, $quality);
            case 'png':
                imagesavealpha($img, true);
                return @imagepng($img, $destPath, 6); // 6 = balanced zlib level
            case 'jpeg':
            default:
                $w = imagesx($img);
                $h = imagesy($img);
                $flat = imagecreatetruecolor($w, $h);
                $white = imagecolorallocate($flat, 255, 255, 255);
                imagefilledrectangle($flat, 0, 0, $w, $h, $white);
                imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
                $ok = @imagejpeg($flat, $destPath, $quality);
                imagedestroy($flat);
                return $ok;
        }
    }
}

if (!function_exists('mt_img_snap_width')) {
    // Snap any requested width UP to the smallest allowed width >= requested
    // (largest allowed if it exceeds all). Keeps the cache bounded.
    function mt_img_snap_width(int $requested): int
    {
        $allowed = MT_IMG_ALLOWED_WIDTHS;
        sort($allowed);
        foreach ($allowed as $w) {
            if ($w >= $requested) {
                return $w;
            }
        }
        return (int) end($allowed);
    }
}

if (!function_exists('mt_img_send_cache_headers')) {
    function mt_img_send_cache_headers(string $mime, string $etag, bool $varyAccept): void
    {
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        if ($varyAccept) {
            header('Vary: Accept');
        }
        header('X-Content-Type-Options: nosniff');
    }
}

if (!function_exists('mt_img_not_modified')) {
    function mt_img_not_modified(string $etag): bool
    {
        return isset($_SERVER['HTTP_IF_NONE_MATCH'])
            && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag;
    }
}

if (!function_exists('mt_img_emit_file')) {
    function mt_img_emit_file(string $file, string $mime, string $etag, bool $varyAccept): void
    {
        if (mt_img_not_modified($etag)) {
            mt_img_send_cache_headers($mime, $etag, $varyAccept);
            http_response_code(304);
            return;
        }
        mt_img_send_cache_headers($mime, $etag, $varyAccept);
        $size = @filesize($file);
        if ($size !== false) {
            header('Content-Length: ' . $size);
        }
        readfile($file);
    }
}

if (!function_exists('mt_img_stream_original')) {
    // Universal graceful fallback: stream the original bytes untouched.
    function mt_img_stream_original(string $absPath, string $ext): void
    {
        $mime = mt_img_mime_for_ext($ext);
        $mtime = @filemtime($absPath) ?: 0;
        $etag = '"orig-' . md5($absPath . '|' . $mtime) . '"';
        mt_img_emit_file($absPath, $mime, $etag, false);
    }
}

if (!function_exists('mt_img_serve')) {
    // The public endpoint brain. Validates the request, serves/generates a
    // cached derivative, and falls back to the original on any problem.
    function mt_img_serve(string $rawName, int $requestedWidth, string $acceptHeader): void
    {
        // 1. Reduce to a bare basename inside uploads/ and confirm it resolves
        //    to a real file physically within the uploads directory.
        $name = basename(str_replace('\\', '/', $rawName));
        $uploadsReal = realpath(MT_IMG_UPLOADS_DIR);
        $absPath = ($name === '' || $name[0] === '.') ? false : realpath(MT_IMG_UPLOADS_DIR . $name);
        if ($uploadsReal === false || $absPath === false || !is_file($absPath)
            || strncmp($absPath, $uploadsReal . DIRECTORY_SEPARATOR, strlen($uploadsReal) + 1) !== 0) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'Not found';
            return;
        }

        $ext = mt_img_ext_of($name);

        // 2. Anything we can't (or shouldn't) resize is streamed as-is:
        //    non-raster (pdf/doc), animated-safe gif pass-through, or no GD.
        if (!in_array($ext, MT_IMG_RASTER_EXT, true) || $ext === 'gif' || !mt_gd_available()) {
            mt_img_stream_original($absPath, $ext);
            return;
        }

        // 3. Bounded width + format negotiation.
        $width = mt_img_snap_width($requestedWidth);
        $useWebp = stripos($acceptHeader, 'image/webp') !== false;
        $outFormat = $useWebp ? 'webp' : 'jpeg';
        $quality = $useWebp ? MT_IMG_WEBP_QUALITY : MT_IMG_JPEG_QUALITY;
        $mime = $useWebp ? 'image/webp' : 'image/jpeg';

        $mtime = @filemtime($absPath) ?: 0;
        $key = sha1(implode('|', [MT_IMG_CACHE_VERSION, $name, $mtime, $width, $outFormat, $quality]));
        $etag = '"' . $key . '"';
        $cacheFile = MT_IMG_CACHE_DIR . $key . '.' . $outFormat;

        // 4. Fast paths: conditional request, then existing cache entry.
        if (mt_img_not_modified($etag)) {
            mt_img_send_cache_headers($mime, $etag, true);
            http_response_code(304);
            return;
        }
        if (is_file($cacheFile)) {
            mt_img_emit_file($cacheFile, $mime, $etag, true);
            return;
        }

        // 5. Generate, cache atomically, and serve. Any failure => original.
        $src = null;
        $resized = null;
        $tmp = null;
        try {
            if (!is_dir(MT_IMG_CACHE_DIR)) {
                @mkdir(MT_IMG_CACHE_DIR, 0775, true);
            }
            $src = mt_img_load($absPath, $ext);
            if (!$src) {
                mt_img_stream_original($absPath, $ext);
                return;
            }
            $src = mt_img_apply_exif_orientation($src, $absPath, $ext);
            $resized = mt_img_resize_width($src, $width);
            $tmp = $cacheFile . '.tmp' . getmypid() . '_' . uniqid('', true);
            $ok = mt_img_encode($resized, $outFormat, $quality, $tmp);
            if ($resized !== $src) {
                imagedestroy($resized);
            }
            imagedestroy($src);
            $src = $resized = null;
            if (!$ok || !is_file($tmp)) {
                if ($tmp) { @unlink($tmp); }
                mt_img_stream_original($absPath, $ext);
                return;
            }
            @rename($tmp, $cacheFile);
            if (is_file($cacheFile)) {
                mt_img_emit_file($cacheFile, $mime, $etag, true);
            } else {
                // rename raced/failed — serve the temp then clean up.
                mt_img_emit_file($tmp, $mime, $etag, true);
                @unlink($tmp);
            }
        } catch (\Throwable $e) {
            if (is_resource($resized) || $resized instanceof \GdImage) {
                if ($resized !== $src) { @imagedestroy($resized); }
            }
            if (is_resource($src) || $src instanceof \GdImage) { @imagedestroy($src); }
            if ($tmp && is_file($tmp)) { @unlink($tmp); }
            mt_img_stream_original($absPath, $ext);
        }
    }
}

if (!function_exists('mt_optimize_uploaded_image')) {
    // Upload-time, in-place optimization for NEW vehicle/profile photos only.
    // Never call this on verification documents. Downscales the longest side
    // to MT_IMG_MAX_UPLOAD_DIM and re-encodes in the SAME format, overwriting
    // the original only when that genuinely helps. Never throws; on any
    // problem the original file is left exactly as uploaded and false is
    // returned (upload still succeeds, just unoptimized).
    function mt_optimize_uploaded_image(string $absPath): bool
    {
        if (!mt_gd_available() || !is_file($absPath)) {
            return false;
        }
        $ext = mt_img_ext_of($absPath);
        if (!in_array($ext, MT_IMG_OPTIMIZE_EXT, true)) {
            return false;
        }

        $src = null;
        $work = null;
        $tmp = null;
        try {
            $origSize = @filesize($absPath) ?: 0;
            $src = mt_img_load($absPath, $ext);
            if (!$src) {
                return false;
            }
            $src = mt_img_apply_exif_orientation($src, $absPath, $ext);
            $oversized = max(imagesx($src), imagesy($src)) > MT_IMG_MAX_UPLOAD_DIM;
            $work = $oversized ? mt_img_resize_longest($src, MT_IMG_MAX_UPLOAD_DIM) : $src;

            $fmt = ($ext === 'jpg' || $ext === 'jpeg') ? 'jpeg' : $ext;
            $tmp = $absPath . '.opt_' . uniqid('', true);
            $ok = mt_img_encode($work, $fmt, MT_IMG_UPLOAD_QUALITY, $tmp);

            if ($work !== $src) {
                imagedestroy($work);
            }
            imagedestroy($src);
            $src = $work = null;

            if (!$ok || !is_file($tmp)) {
                if ($tmp) { @unlink($tmp); }
                return false;
            }

            $newSize = @filesize($tmp) ?: 0;
            // Keep the re-encode only if we downscaled (dimensions shrank) or
            // it is actually smaller on disk; otherwise leave the original.
            if ($oversized || ($newSize > 0 && $newSize < $origSize)) {
                if (@rename($tmp, $absPath)) {
                    return true;
                }
            }
            @unlink($tmp);
            return false;
        } catch (\Throwable $e) {
            if (($work ?? null) && $work !== ($src ?? null)) { @imagedestroy($work); }
            if (($src ?? null)) { @imagedestroy($src); }
            if ($tmp && is_file($tmp)) { @unlink($tmp); }
            return false;
        }
    }
}
