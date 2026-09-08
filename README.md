# fastresize-php

PHP FFI binding for [fastresize](https://github.com/mattsplat/fastresize) -
a small, single-purpose C++ image decode/resize/composite/encode library. No
algorithm is reimplemented here: `src/FastResize.php` calls straight into
`fastresize_capi.h`'s C ABI shim (bundled in `native/`) via PHP's `ext-ffi`.

## Install

```sh
composer require mattsplat/fastresize-php
```

Then build the native library - **not done automatically by `composer
install`**, deliberately: compiling C++ on every consumer's install (CI
included) is a bigger surprise than one explicit extra step.

```sh
composer run-script build   # or: make -C vendor/mattsplat/fastresize-php/native
```

This curls the pinned `stb_image`/`stb_image_resize2`/`stb_image_write`
headers and [fpng](https://github.com/richgel999/fpng) (`v1.0.6`, the PNG
encoder - ~12x faster than `stb_image_write`, ~8% larger files) into
`native/vendor/` and compiles `libfastresize_capi.so` (`.dylib` on macOS)
into the package root, right next to `native/`. Requires a C++17 compiler
(`clang++` by default - override with `make CXX=g++`) and `curl`.
`ext-ffi` must be enabled (`ffi.enable=1` in php.ini, or `"preload"` per the
[PHP FFI docs](https://www.php.net/manual/en/book.ffi.php) for non-CLI
SAPIs).

## Usage

```php
use Fastresize\FastResize;

$bytes = file_get_contents('background.png');
[$width, $height] = FastResize::probeDimensions($bytes); // header only, no decode

$bg = FastResize::decode($bytes);
$fg = FastResize::decode(file_get_contents('foreground.png'));

// crop the decoded foreground to its non-transparent region before resizing,
// so a mostly-transparent asset only costs its drawing to cache/composite.
[$ox, $oy, $ow, $oh] = FastResize::opaqueRect($fg);
$fgCropped = FastResize::crop($fg, $ox, $oy, $ow, $oh);
$fgSmall = FastResize::resizeNearest($fgCropped, 200, 200);

// solid white canvas so a 3-channel encode has an honest background
$canvas = FastResize::newCanvas($bg->width(), $bg->height(), 255, 255, 255, 255);
FastResize::compositeOver($canvas, $bg, 0, 0);
FastResize::compositeOver($canvas, $fgSmall, 40, 40);

// encodePngRgb flattens transparency onto the background and drops the alpha
// channel - a 3-channel PNG a downstream decoder (e.g. a PDF) can take fast.
file_put_contents('out.png', FastResize::encodePngRgb($canvas));
// ...or FastResize::encodePng($canvas) for a 4-channel RGBA PNG.

// FRImage handles are native heap memory, not PHP-GC'd - free every one
// you allocated, exactly once.
FastResize::free($bg);
FastResize::free($fg);
FastResize::free($fgCropped);
FastResize::free($fgSmall);
FastResize::free($canvas);
```

See `fastresize_capi.h` in `native/` (or the
[fastresize](https://github.com/mattsplat/fastresize) repo) for the full
underlying API and `fastresize.h`'s own header comment for the case against
libvips this library is making.

## Tests

```sh
make -C native          # the tests need the compiled library
composer install
composer test            # phpunit
```

`tests/FastResizeTest.php` drives every method against the real compiled
library (decode/probe/resize/crop/fill/composite/encode, opaque-rect
tightness, RGB-vs-RGBA output, transparent-pixel flattening). The pixel
assertions use `ext-gd` and self-skip if it is missing. CI runs the suite
on PHP 8.1–8.4.

## License

MIT - see [LICENSE](./LICENSE).
