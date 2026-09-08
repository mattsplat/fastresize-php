# Builds libfastresize_capi.so/.dylib from fastresize_capi.cpp (the C ABI
# shim over fastresize.h - see https://github.com/mattsplat/fastresize)
# into the PACKAGE ROOT (one level up), where src/FastResize.php expects it.
#
#   make        # curl the pinned stb + fpng sources into ./vendor, build it
#   make clean
#
# PNG encoding uses fpng (FASTRESIZE_FPNG), not stb_image_write: measured
# ~12x faster on a large canvas, at the cost of ~8% larger files. See the
# encodePng() comment in fastresize.h.

STB_COMMIT := f0569113c93ad095470c54bf34a17b36646bbbb5
STB_BASE   := https://raw.githubusercontent.com/nothings/stb/$(STB_COMMIT)

# fpng v1.0.6
FPNG_COMMIT := f61458966a0dcb37172305c062d06fd3ce54e7c5
FPNG_BASE   := https://raw.githubusercontent.com/richgel999/fpng/$(FPNG_COMMIT)/src

VENDOR     := vendor
HEADERS    := $(VENDOR)/stb_image.h $(VENDOR)/stb_image_write.h $(VENDOR)/stb_image_resize2.h \
              $(VENDOR)/fpng.h
FPNG_SRC   := $(VENDOR)/fpng.cpp

CXX     ?= clang++
ARCH    := $(shell uname -m)
ifneq (,$(filter $(ARCH),x86_64 amd64))
  MARCH     := -march=x86-64-v3
  FPNG_ARCH := -msse4.1 -mpclmul
else ifneq (,$(filter $(ARCH),aarch64 arm64))
  MARCH     := -mcpu=native
  # fpng has no NEON path; disable its SSE code so it uses the scalar CRC.
  FPNG_ARCH := -DFPNG_NO_SSE=1
else
  MARCH     :=
  FPNG_ARCH := -DFPNG_NO_SSE=1
endif

CXXFLAGS ?= -O3 $(MARCH) -std=c++17
# fpng.cpp type-puns through pointers by design; -fno-strict-aliasing is the
# author's documented requirement and is harmless for the shim TU too.
CPPFLAGS := -DFASTRESIZE_FPNG -fno-strict-aliasing -I $(VENDOR)

UNAME_S := $(shell uname -s)
ifeq ($(UNAME_S),Darwin)
  SOEXT := dylib
else
  SOEXT := so
endif

OUT := ../libfastresize_capi.$(SOEXT)

.PHONY: all clean
all: $(OUT)

$(OUT): fastresize_capi.cpp fastresize_capi.h fastresize.h $(HEADERS) $(VENDOR)/fpng.o
	$(CXX) $(CXXFLAGS) $(CPPFLAGS) -fPIC -shared -o $@ fastresize_capi.cpp $(VENDOR)/fpng.o

$(VENDOR)/fpng.o: $(FPNG_SRC) $(VENDOR)/fpng.h
	$(CXX) $(CXXFLAGS) $(FPNG_ARCH) -fno-strict-aliasing -I $(VENDOR) -fPIC -c -o $@ $(FPNG_SRC)

$(VENDOR)/stb_%.h:
	@mkdir -p $(VENDOR)
	curl -sSL -o $@ $(STB_BASE)/stb_$*.h

$(VENDOR)/fpng.h:
	@mkdir -p $(VENDOR)
	curl -sSL -o $@ $(FPNG_BASE)/fpng.h

$(VENDOR)/fpng.cpp:
	@mkdir -p $(VENDOR)
	curl -sSL -o $@ $(FPNG_BASE)/fpng.cpp

clean:
	rm -f ../libfastresize_capi.so ../libfastresize_capi.dylib
	rm -rf $(VENDOR)
