<?php

namespace Fastresize;

use FFI;
use FFI\CData;

/**
 * PHP FFI binding over fastresize_capi.h (native/) - the C ABI shim around
 * fastresize.h (https://github.com/mattsplat/fastresize). No algorithm is
 * reimplemented here, this class just calls into libfastresize_capi.so,
 * built from native/ via `composer run-script build` (or `make -C native`) -
 * see this package's README before first use. A drop-in alternative to
 * jcupitt/vips (also FFI-based) for projects that measured libvips slower
 * than a plain decode/resize/composite/encode pipeline for their workload.
 */
final class FastResize
{
    private static ?FFI $ffi = null;

    private static function ffi(): FFI
    {
        if (self::$ffi === null) {
            self::$ffi = FFI::cdef(<<<'CDEF'
                typedef struct FRImage FRImage;
                FRImage* fr_decode(const uint8_t* data, size_t len);
                int fr_probe_dimensions(const uint8_t* data, size_t len, int* out_width, int* out_height);
                FRImage* fr_resize_nearest(const FRImage* src, int target_width, int target_height);
                FRImage* fr_new_canvas(int width, int height);
                void fr_composite_over(FRImage* canvas, const FRImage* src, int x, int y);
                int fr_width(const FRImage* img);
                int fr_height(const FRImage* img);
                int fr_encode_png(const FRImage* img, uint8_t** out_data, size_t* out_len);
                void fr_free_buffer(uint8_t* data);
                void fr_image_free(FRImage* img);
                const char* fr_last_error(void);
                CDEF, self::libraryPath());
        }
        return self::$ffi;
    }

    // .so on Linux, .dylib on macOS - matches native/Makefile's SOEXT logic,
    // both built to the package root (one level up from src/).
    private static function libraryPath(): string
    {
        $base = __DIR__ . '/../libfastresize_capi';
        foreach (['so', 'dylib'] as $ext) {
            if (is_file("$base.$ext")) {
                return "$base.$ext";
            }
        }
        throw new \RuntimeException(
            "libfastresize_capi.{so,dylib} not found next to the package root - " .
            "run `composer run-script build` (or `make -C native`) first. See README.md."
        );
    }

    private static function lastError(): string
    {
        return self::ffi()->fr_last_error();
    }

    // PHP FFI does NOT auto-cast a PHP string to uint8_t* the way it does
    // for char*/const char* - measured directly against the real compiled
    // library ("Passing incompatible argument 1... expecting 'uint8_t*',
    // found PHP 'string'"), so every call taking raw image bytes goes
    // through this to build a real CData buffer first.
    private static function toCBuffer(string $bytes): CData
    {
        $ffi = self::ffi();
        $len = strlen($bytes);
        $buf = $ffi->new("uint8_t[$len]", false);
        FFI::memcpy($buf, $bytes, $len);
        return $buf;
    }

    /** @return array{0: int, 1: int} [width, height] */
    public static function probeDimensions(string $bytes): array
    {
        $ffi = self::ffi();
        $buf = self::toCBuffer($bytes);
        $w = $ffi->new('int');
        $h = $ffi->new('int');
        $rc = $ffi->fr_probe_dimensions($buf, strlen($bytes), FFI::addr($w), FFI::addr($h));
        if ($rc !== 0) {
            throw new \RuntimeException('fastresize probe failed: ' . self::lastError());
        }
        return [$w->cdata, $h->cdata];
    }

    public static function decode(string $bytes): FastImage
    {
        $ffi = self::ffi();
        $buf = self::toCBuffer($bytes);
        $ptr = $ffi->fr_decode($buf, strlen($bytes));
        if (FFI::isNull($ptr)) {
            throw new \RuntimeException('fastresize decode failed: ' . self::lastError());
        }
        return new FastImage($ptr);
    }

    public static function newCanvas(int $width, int $height): FastImage
    {
        $ptr = self::ffi()->fr_new_canvas($width, $height);
        if (FFI::isNull($ptr)) {
            throw new \RuntimeException('fastresize canvas allocation failed: ' . self::lastError());
        }
        return new FastImage($ptr);
    }

    public static function resizeNearest(FastImage $src, int $targetWidth, int $targetHeight): FastImage
    {
        $ptr = self::ffi()->fr_resize_nearest($src->ptr(), $targetWidth, $targetHeight);
        if (FFI::isNull($ptr)) {
            throw new \RuntimeException('fastresize resize failed: ' . self::lastError());
        }
        return new FastImage($ptr);
    }

    public static function compositeOver(FastImage $canvas, FastImage $src, int $x, int $y): void
    {
        self::ffi()->fr_composite_over($canvas->ptr(), $src->ptr(), $x, $y);
    }

    public static function width(FastImage $img): int
    {
        return self::ffi()->fr_width($img->ptr());
    }

    public static function height(FastImage $img): int
    {
        return self::ffi()->fr_height($img->ptr());
    }

    public static function encodePng(FastImage $img): string
    {
        $ffi = self::ffi();
        $outData = $ffi->new('uint8_t*');
        $outLen = $ffi->new('size_t');
        $rc = $ffi->fr_encode_png($img->ptr(), FFI::addr($outData), FFI::addr($outLen));
        if ($rc !== 0) {
            throw new \RuntimeException('fastresize encode failed: ' . self::lastError());
        }
        $result = FFI::string($outData, $outLen->cdata);
        $ffi->fr_free_buffer($outData);
        return $result;
    }

    public static function free(FastImage $img): void
    {
        self::ffi()->fr_image_free($img->ptr());
    }
}
