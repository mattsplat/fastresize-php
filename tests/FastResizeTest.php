<?php

declare(strict_types=1);

namespace Fastresize\Tests;

use Fastresize\FastImage;
use Fastresize\FastResize;
use PHPUnit\Framework\TestCase;

final class FastResizeTest extends TestCase
{
    /** @var list<FastImage> */
    private array $allocated = [];

    protected function tearDown(): void
    {
        // FRImage handles are native heap memory, not PHP-GC'd.
        foreach ($this->allocated as $img) {
            FastResize::free($img);
        }
        $this->allocated = [];
    }

    private function track(FastImage $img): FastImage
    {
        $this->allocated[] = $img;
        return $img;
    }

    // A `w`x`h` transparent canvas with one opaque `[r,g,b]` rectangle painted
    // at ($x, $y, $rw, $rh), returned as PNG bytes. Built with the library
    // itself, so fixture creation needs nothing external.
    private function squarePng(
        int $w,
        int $h,
        int $x,
        int $y,
        int $rw,
        int $rh,
        array $rgb = [255, 0, 0],
    ): string {
        $canvas = $this->track(FastResize::newCanvas($w, $h));
        if ($rw > 0 && $rh > 0) {
            $square = $this->track(FastResize::newCanvas($rw, $rh, $rgb[0], $rgb[1], $rgb[2], 255));
            FastResize::compositeOver($canvas, $square, $x, $y);
        }
        return FastResize::encodePng($canvas);
    }

    /** PNG IHDR colour-type byte: 2 = truecolour (RGB), 6 = truecolour + alpha. */
    private function pngColorType(string $png): int
    {
        // 8 sig + 4 length + 4 "IHDR" + 4 width + 4 height + 1 bit-depth = 25.
        return \ord($png[25]);
    }

    /** @return array{0: int, 1: int, 2: int} [r, g, b] */
    private function pixelAt(string $png, int $x, int $y): array
    {
        if (!\extension_loaded('gd')) {
            self::markTestSkipped('ext-gd not available for pixel inspection');
        }
        $im = \imagecreatefromstring($png);
        self::assertNotFalse($im, 'GD could not read the encoded PNG');
        $c = \imagecolorat($im, $x, $y);
        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    public function testProbeDimensionsReadsHeader(): void
    {
        $png = $this->squarePng(64, 48, 10, 10, 20, 20);
        self::assertSame([64, 48], FastResize::probeDimensions($png));
    }

    public function testDecodeExposesDimensions(): void
    {
        $img = $this->track(FastResize::decode($this->squarePng(30, 20, 0, 0, 30, 20)));
        self::assertSame(30, $img->width());
        self::assertSame(20, $img->height());
    }

    public function testNewCanvasTransparentAllocates(): void
    {
        $img = $this->track(FastResize::newCanvas(16, 24));
        self::assertSame(16, $img->width());
        self::assertSame(24, $img->height());
    }

    public function testNewCanvasWithBackgroundIsFullyOpaque(): void
    {
        $img = $this->track(FastResize::newCanvas(32, 32, 255, 255, 255, 255));
        self::assertSame([0, 0, 32, 32], FastResize::opaqueRect($img));
    }

    public function testFillMakesCanvasOpaque(): void
    {
        $img = $this->track(FastResize::newCanvas(20, 10));
        FastResize::fill($img, 0, 128, 255, 255);
        self::assertSame([0, 0, 20, 10], FastResize::opaqueRect($img));
    }

    public function testFillWithZeroAlphaClearsOpaqueRect(): void
    {
        $img = $this->track(FastResize::newCanvas(8, 8, 255, 255, 255, 255));
        FastResize::fill($img, 0, 0, 0, 0);
        self::assertSame([0, 0, 0, 0], FastResize::opaqueRect($img));
    }

    public function testResizeNearestProducesExactTargetSize(): void
    {
        $src = $this->track(FastResize::decode($this->squarePng(40, 40, 5, 5, 30, 30)));
        $out = $this->track(FastResize::resizeNearest($src, 17, 9));
        self::assertSame(17, $out->width());
        self::assertSame(9, $out->height());
    }

    public function testCompositeOverBlendsSquareOntoCanvas(): void
    {
        $png = $this->squarePng(40, 40, 10, 10, 20, 20, [10, 200, 30]);
        self::assertSame([10, 200, 30], $this->pixelAt($png, 20, 20));
    }

    public function testOpaqueRectIsTightAfterDecode(): void
    {
        $img = $this->track(FastResize::decode($this->squarePng(64, 48, 10, 12, 20, 16)));
        self::assertSame([10, 12, 20, 16], FastResize::opaqueRect($img));
    }

    public function testOpaqueRectIsZeroForFullyTransparentImage(): void
    {
        $img = $this->track(FastResize::decode($this->squarePng(16, 16, 0, 0, 0, 0)));
        self::assertSame([0, 0, 0, 0], FastResize::opaqueRect($img));
    }

    public function testCropReturnsRequestedRectSize(): void
    {
        $src = $this->track(FastResize::decode($this->squarePng(64, 48, 10, 10, 20, 20)));
        $crop = $this->track(FastResize::crop($src, 10, 10, 20, 20));
        self::assertSame(20, $crop->width());
        self::assertSame(20, $crop->height());
    }

    public function testCropClampsOutOfBoundsToSource(): void
    {
        $src = $this->track(FastResize::decode($this->squarePng(64, 48, 0, 0, 64, 48)));
        $crop = $this->track(FastResize::crop($src, -5, -5, 1000, 1000));
        self::assertSame(64, $crop->width());
        self::assertSame(48, $crop->height());
    }

    public function testCropToOpaqueRegionMatchesOpaqueRect(): void
    {
        $src = $this->track(FastResize::decode($this->squarePng(64, 48, 8, 6, 24, 18)));
        [$x, $y, $w, $h] = FastResize::opaqueRect($src);
        $crop = $this->track(FastResize::crop($src, $x, $y, $w, $h));
        self::assertSame([24, 18], [$crop->width(), $crop->height()]);
    }

    public function testEncodePngIsRgba(): void
    {
        $canvas = $this->track(FastResize::newCanvas(8, 8, 255, 255, 255, 255));
        self::assertSame(6, $this->pngColorType(FastResize::encodePng($canvas)));
    }

    public function testEncodePngRgbIsRgb(): void
    {
        $canvas = $this->track(FastResize::newCanvas(8, 8, 255, 255, 255, 255));
        self::assertSame(2, $this->pngColorType(FastResize::encodePngRgb($canvas)));
    }

    public function testEncodePngRgbFlattensTransparentPixelsOntoBackground(): void
    {
        $canvas = $this->track(FastResize::newCanvas(40, 40)); // transparent
        $square = $this->track(FastResize::newCanvas(20, 20, 255, 0, 0, 255));
        FastResize::compositeOver($canvas, $square, 5, 5);

        $png = FastResize::encodePngRgb($canvas, 10, 20, 30);

        self::assertSame([10, 20, 30], $this->pixelAt($png, 0, 0), 'transparent pixel -> background');
        self::assertSame([255, 0, 0], $this->pixelAt($png, 10, 10), 'opaque pixel unchanged');
    }

    public function testEncodePngRgbDefaultsToWhiteBackground(): void
    {
        $canvas = $this->track(FastResize::newCanvas(8, 8)); // transparent
        self::assertSame([255, 255, 255], $this->pixelAt(FastResize::encodePngRgb($canvas), 0, 0));
    }

    public function testEncodePngRgbRoundTripsThroughDecode(): void
    {
        $canvas = $this->track(FastResize::newCanvas(40, 24, 255, 255, 255, 255));
        $decoded = $this->track(FastResize::decode(FastResize::encodePngRgb($canvas)));
        self::assertSame(40, $decoded->width());
        self::assertSame(24, $decoded->height());
    }

    public function testDecodeRejectsGarbage(): void
    {
        $this->expectException(\RuntimeException::class);
        FastResize::decode('not a png');
    }
}
