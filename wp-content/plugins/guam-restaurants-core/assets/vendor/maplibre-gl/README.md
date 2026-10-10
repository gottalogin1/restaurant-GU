# MapLibre GL JS 6.13.0

Unmodified `dist/maplibre-gl.mjs`, `dist/maplibre-gl-worker.mjs` and
`dist/maplibre-gl.css` from the
[maplibre-gl npm package](https://www.npmjs.com/package/maplibre-gl), version
6.13.0 (BSD-3-Clause, see LICENSE.txt), with only the source map comments
removed. Served from the plugin so map pages need no third-party script host.
`assets/site/map.js` imports it only when a map is about to be shown.

Version 6 is published only as ES modules. `maplibre-gl.mjs` starts its web
worker from `maplibre-gl-worker.mjs` in the same folder, so keep the two files
together, and make sure the web server sends `.mjs` files with a JavaScript
content type (Apache and nginx do by default; docs/deployment.md).

Updated from 5.24.0 on 2026-10-10 because 5.x is affected by CVE-2026-85061
(XSS through the HTML sanitizer used for attribution and popups; fixed in
6.4.1, with no 5.x release). See docs/security.md §6.

To update: `npm pack maplibre-gl@<version>`, copy the three files from
`package/dist/`, delete the `//# sourceMappingURL=` line at the end of the two
`.mjs` files, change the version in this file, and check the map page and a
restaurant page in a browser.
