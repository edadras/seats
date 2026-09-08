# zxing-wasm 3.1.1, vendored

`mobile_scanner` loads its software barcode decoder from jsDelivr, and the decoder then fetches its
own `.wasm` from jsDelivr as well. Both are needed before a single ticket can be read.

A door scanner cannot depend on that. Venue wifi is captive, filtered, or simply bad, and the one
night the CDN is unreachable is the night three hundred people are queuing outside. So both files
are served from our own origin instead, and `index.html` wires them up before Flutter starts.

Files, taken verbatim from the published package:

- `index.js` — `https://cdn.jsdelivr.net/npm/zxing-wasm@3.1.1/dist/iife/reader/index.js`
- `zxing_reader.wasm` — `https://cdn.jsdelivr.net/npm/zxing-wasm@3.1.1/dist/reader/zxing_reader.wasm`

zxing-wasm is Apache-2.0, as is the zxing-cpp it compiles.

## Updating

The version is pinned by `mobile_scanner` in `lib/src/web/web_library_versions.dart`
(`zxingWasmVersion`). When that package is upgraded, re-download both files at the new version —
a decoder and a `.wasm` from different releases will not load each other.
