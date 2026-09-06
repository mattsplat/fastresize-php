<?php

namespace Fastresize;

use FFI\CData;

/**
 * Owning wrapper around an FRImage* handle. PHP FFI CData pointers aren't
 * freed by PHP's GC (they're just opaque handles to it) - callers MUST call
 * FastResize::free() on every instance exactly once; this class just gives
 * that pointer a name to pass around.
 */
final class FastImage
{
    public function __construct(private readonly CData $ptr)
    {
    }

    public function ptr(): CData
    {
        return $this->ptr;
    }

    public function width(): int
    {
        return FastResize::width($this);
    }

    public function height(): int
    {
        return FastResize::height($this);
    }
}
