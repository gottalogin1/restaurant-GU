# MapLibre GL JS 5.24.0

Unmodified `dist/maplibre-gl.js` and `dist/maplibre-gl.css` from the
[maplibre-gl npm package](https://www.npmjs.com/package/maplibre-gl), version
5.24.0 (BSD-3-Clause, see LICENSE.txt), with only the source map comment
removed. Served from the plugin so map pages need no third-party script host.
`assets/site/map.js` loads it only when a map is about to be shown.

To update: `npm pack maplibre-gl@<version>`, copy the two files from
`package/dist/`, and change the version in this file.
