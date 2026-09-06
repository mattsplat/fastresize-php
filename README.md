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
headers into `native/vendor/` and compiles `libfastresize_capi.so` (`.dylib`
on macOS) into the package root, right next to `native/`. Requires a C++17
compiler (`clang++` by default - override with `make CXX=g++`) and `curl`.
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
$fg = FastResize::resizeNearest($fg, 200, 200);

$canvas = FastResize::newCanvas($bg->width(), $bg->height());
FastResize::compositeOver($canvas, $bg, 0, 0);
FastResize::compositeOver($canvas, $fg, 40, 40);

file_put_contents('out.png', FastResize::encodePng($canvas));

// FRImage handles are native heap memory, not PHP-GC'd - free every one
// you allocated, exactly once.
FastResize::free($bg);
FastResize::free($fg);
FastResize::free($canvas);
```

See `fastresize_capi.h` in `native/` (or the
[fastresize](https://github.com/mattsplat/fastresize) repo) for the full
underlying API and `fastresize.h`'s own header comment for the case against
libvips this library is making.

## License

MIT - see [LICENSE](./LICENSE).
