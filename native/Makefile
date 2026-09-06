# Builds libfastresize_capi.so/.dylib from fastresize_capi.cpp (the C ABI
# shim over fastresize.h - see https://github.com/mattsplat/fastresize)
# into the PACKAGE ROOT (one level up), where src/FastResize.php expects it.
#
#   make        # curl the pinned stb headers into ./vendor, build it
#   make clean

STB_COMMIT := f0569113c93ad095470c54bf34a17b36646bbbb5
STB_BASE   := https://raw.githubusercontent.com/nothings/stb/$(STB_COMMIT)
VENDOR     := vendor
HEADERS    := $(VENDOR)/stb_image.h $(VENDOR)/stb_image_write.h $(VENDOR)/stb_image_resize2.h

CXX     ?= clang++
ARCH    := $(shell uname -m)
ifneq (,$(filter $(ARCH),x86_64 amd64))
  MARCH := -march=x86-64-v3
else ifneq (,$(filter $(ARCH),aarch64 arm64))
  MARCH := -mcpu=native
else
  MARCH :=
endif
CXXFLAGS ?= -O3 $(MARCH) -std=c++17

UNAME_S := $(shell uname -s)
ifeq ($(UNAME_S),Darwin)
  SOEXT := dylib
else
  SOEXT := so
endif

OUT := ../libfastresize_capi.$(SOEXT)

.PHONY: all clean
all: $(OUT)

$(OUT): fastresize_capi.cpp fastresize_capi.h fastresize.h $(HEADERS)
	$(CXX) $(CXXFLAGS) -fPIC -shared -I $(VENDOR) -o $@ fastresize_capi.cpp

$(VENDOR)/stb_%.h:
	@mkdir -p $(VENDOR)
	curl -sSL -o $@ $(STB_BASE)/stb_$*.h

clean:
	rm -f ../libfastresize_capi.so ../libfastresize_capi.dylib
	rm -rf $(VENDOR)
