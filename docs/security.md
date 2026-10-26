# Security

Production security review, 2026-10-10. Covers the custom plugin and theme, the
WordPress setup, and the owned plugins from CLAUDE.md rule 6 that do the jobs the
plugin should not (login limits, firewall, malware scans). `wp guam-restaurants
preflight` checks most settings below on the running site (docs/deployment.md §4).

## 1. Code review of `guam-restaurants-core`

Reviewed every request handler, REST route, query and template in the plugin
(about 32,000 lines, 188 PHP files).

| Area | What was checked | Result |
|---|---|---|
| Nonces | Every `admin_post_*` handler, meta-box save and CSV export | All call `check_admin_referer()` or `wp_verify_nonce()` before acting. Public forms (reviews, corrections, advertiser inquiries) load their nonce over REST so cached pages still work, and add a honeypot and a minimum fill time. |
| Capability checks | Admin screens, meta-box saves, REST write routes | Every handler checks a capability (`gr_moderate_highlights`, `gr_manage_ads`, `gr_approve_claims`, `edit_post`, `edit_term`, ...) or `Roles::can_speak_for()` for owners. The billing, premium and ad meta boxes check `current_user_can()` inside `save_billing()`, `save_input()` and `save_ad()`, after the nonce. |
| Public REST routes | 9 routes with `permission_callback => __return_true` | All are meant to be public: read-only data (directory, map, ratings, reviews), form submissions with nonce, honeypot and rate limits, and the ad and listing beacons, which store only daily totals. |
| Escaping | Templates, blocks, admin screens | PHPCS (`WordPress.Security.EscapeOutput`) passes. The 11 `phpcs:ignore` exceptions were read one by one: each prints markup that was escaped where it was built (block HTML, `wp_get_script_tag()`, cells escaped by the caller). |
| SQL | Every `$wpdb` call | All values go through `$wpdb->prepare()`. The 6 unprepared queries contain no input at all (`COUNT(*)` on our own tables, a `DELETE` of the whole open-hours index). `IN (...)` lists are built from `%d`/`%s` placeholders. |
| File uploads | Owner photos (`Owners\Dashboard::upload()`) | 5 MB cap, JPEG/PNG/WebP only, checked with `wp_check_filetype_and_ext()` and `media_handle_upload()` with a fixed MIME list, photo count capped per plan, alt text required. Added site-wide limits (§2). |
| Input | `$_GET`/`$_POST`/`$_FILES` reads | Each is unslashed and sanitized (`absint`, `sanitize_key`, `sanitize_text_field`, `esc_url_raw`) or passed to a class that sanitizes field by field. |
| Secrets | Repository and `.env.example` | No keys committed; all API keys are `wp-config.php` constants or environment variables (rule 8). |

**Found and fixed in this review**

- **MapLibre GL JS 5.24.0 had a critical XSS** (CVE-2026-85061, sanitizer bypass;
  fixed in 6.4.1, no 5.x release). Map attribution comes from the third-party tile
  style, so a compromised or malicious style could run script on our pages. Updated to
  6.13.0 (§6).
- **Rate limits behind a CDN.** Form limits were keyed on `REMOTE_ADDR`. Behind
  Cloudflare or a host CDN every visitor shares a few CDN addresses, so one person's
  limit would have blocked everyone. `GUAM_RESTAURANTS_CLIENT_IP_HEADER` now names the
  header with the real address (§5).

**Known gaps (open items)**

- ~~The ad and listing beacons have no rate limit~~ (fixed 2026-10-26, §1a). A CDN or
  WAF rule (§5) is still needed against many addresses at once.
- Form data (leads, corrections, claims) has no automatic retention purge and no
  WordPress privacy export/erase hooks yet. The privacy policy draft leaves the
  retention period for counsel.

## 1a. Second review, 2026-10-26

Re-read the plugin after it grew to about 47,000 lines in 259 PHP files (photos,
saved lists and sign-in, translations, dish pages, plain-language search and open-at
came after §1). Same method: every REST route and `permission_callback`, every
`admin_post_*` handler, every `$_GET`/`$_POST`/`$_FILES` read, every `phpcs:ignore`
on an escaping, input or SQL sniff, the front-end scripts that build HTML, and the
upload paths. PHPCS (WordPress, including `WordPress.Security`) and `composer audit`
are clean; npm's advisory database has nothing for MapLibre 6.13.0.

Nonces, capability checks, SQL preparation and escaping held up: no handler without a
nonce and capability (or owner) check, no query with unprepared input, and the
third-party review links in `ratings.js` only accept `http(s)` URLs.

**Found and fixed**

| # | Finding | Risk | Fix |
|---|---|---|---|
| 1 | Ad beacon (`/ads/events`) and owner listing beacon (`/listing/events`) had no limit. | Anyone could inflate an advertiser's impressions and clicks, or a restaurant's dashboard numbers, with a loop from one machine. | `Security\Rate_Limit`: 240 ad beacon requests and 120 listing requests an hour per visitor (hashed IP), refused quietly. The listing view counter uses the same class. |
| 2 | Visitor photo originals (with camera and GPS data) were stored in the public month folder under a random name. | Readable by anyone who learns the name, and listed outright on a server with directory listing on. Could reveal where a visitor lives. | Originals go to `wp-content/uploads/gr-private/photo-originals/`, which has `Require all denied` and an empty `index.php`. A migration moves existing originals. nginx needs the rule in §2. |
| 3 | oEmbed answers (`/wp-json/oembed/1.0/embed?url=...`) named each post's author and linked the author archive. | Gave away login names (checked locally: `admin` and `/author/admin/`) despite the other user-enumeration blocks. | Author fields removed from oEmbed answers. |
| 4 | The sign-in page said "the password you entered for the username X is incorrect" vs "not registered". | Told an attacker which names and emails have accounts. | One message for unknown user, unknown email and wrong password. |
| 5 | Application passwords were available. | They sign in to the REST API with no second step, so they undo the two-factor rule for administrators. Nothing on the site uses them. | Off unless `GUAM_RESTAURANTS_ALLOW_APP_PASSWORDS=1`. |
| 6 | Map popups escaped names with a helper that left `"` alone, then put the URL in `href="..."`. | Low: the URL is the site's own permalink, but any future field there could break out of the attribute. | Escapes `& < > " '`. |
| 7 | PHP's `X-Powered-By: PHP/8.x` header was sent. | Tells scanners which PHP to target. | Removed; also `Cross-Origin-Opener-Policy: same-origin-allow-popups` and `X-Permitted-Cross-Domain-Policies: none`. |

`wp guam-restaurants preflight` now also checks that application passwords are off,
that the private folder really refuses a request (it writes a probe file and asks the
web server for it), and that the uploads folder does not list its files.

**Still open (needs the live host or a decision)**

- Administrators without two-factor are sent to their profile on wp-admin screens, but
  REST and `admin-post.php` still work for them (the Two Factor plugin sets itself up
  over REST). Enrolling every administrator and turning on
  `GUAM_RESTAURANTS_REQUIRE_ADMIN_2FA` closes this in practice.
- Privacy export/erase hooks and a retention purge for leads, corrections and claims
  (from §1).
- The CSP still allows `'unsafe-inline'` scripts (WordPress core, WooCommerce and
  PayPal need it).

## 2. Hardening built into the plugin (`Security\Hardening`)

- XML-RPC is off (`xmlrpc_enabled` false and no methods), unless
  `GUAM_RESTAURANTS_ALLOW_XMLRPC=1`. Block `/xmlrpc.php` at the server too (§3).
- No user enumeration: `/wp-json/wp/v2/users` needs a signed-in user, `?author=N` and
  author archives return 404 to visitors, and the core sitemap has no users list.
- No WordPress version in the page head or feeds.
- Uploads: one cap for everyone, `GUAM_RESTAURANTS_MAX_UPLOAD_MB` (default 20, the
  server's `upload_max_filesize` still applies when lower); images over 40 megapixels
  are refused before thumbnails are made; people without `manage_options` can upload
  only JPEG, PNG, GIF, WebP, AVIF and PDF.
- Application passwords off (they skip two-factor), unless
  `GUAM_RESTAURANTS_ALLOW_APP_PASSWORDS=1`. Failed sign-ins show one message whatever
  was wrong, and oEmbed answers carry no author.
- Visitor photo uploads (`Photos\Photo_Submissions`): REST, multipart, JPEG/PNG/WebP
  only (checked from the file's bytes), up to 20 MB and 40 megapixels. Honeypot,
  3-second minimum fill, 5 uploads per hour per hashed IP and per email.
  Visitors who are not logged in confirm by email first. The original is stored
  under a random name in the private folder (below) and deleted after 30 days. The public copy has its
  camera and location data removed. Owners' photos also wait for approval unless the
  owner has the "Restaurant owner (trusted)" role.

**Private uploads folder (`Security\Private_Uploads`).** `wp-content/uploads/gr-private/`
holds files the site keeps but never shows (photo originals). The plugin writes an
`.htaccess` that refuses every request, which Apache obeys. **nginx ignores it**, so
add this to the site's server block, then run `wp guam-restaurants preflight` and
expect "Private uploads closed: ok":

```nginx
location ^~ /wp-content/uploads/gr-private/ { deny all; }
# No PHP from uploads, and no folder listings anywhere.
location ~* ^/wp-content/uploads/.*\.php$ { deny all; }
autoindex off;
```

On Apache, also stop PHP from running in uploads by putting this in
`wp-content/uploads/.htaccess`:

```apache
<FilesMatch "\.(php|phtml|phar)$">
  Require all denied
</FilesMatch>
Options -Indexes
```

**`wp-config.php` settings** (in docs/deployment.md §2): `DISALLOW_FILE_EDIT`,
`WP_DEBUG_DISPLAY` false, `FORCE_SSL_ADMIN`, fresh salts, and `WP_ENVIRONMENT_TYPE`.

**Accounts**

- Do not keep a user named `admin`. Each person gets their own account; administrators
  only for people who run the site. Editors write editor's notes; owners get the
  Restaurant owner role from an approved claim.
- `default_role` must stay `subscriber` (preflight fails otherwise). "Anyone can
  register" is needed for owner claims (docs/billing.md §4).

## 3. Security headers (`Security\Headers`)

The plugin sends these on every page, wp-admin and the login page:

| Header | Value |
|---|---|
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `X-Frame-Options` | `SAMEORIGIN` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), usb=(), payment=(self "https://www.paypal.com")` |
| `Cross-Origin-Opener-Policy` | `same-origin-allow-popups` (PayPal and Google sign-in popups still work) |
| `X-Permitted-Cross-Domain-Policies` | `none` |
| `X-Powered-By` | removed |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains`, only with `GUAM_RESTAURANTS_HSTS=1` and HTTPS |
| `Content-Security-Policy-Report-Only` | public pages; see below |

**CSP.** Report-only at first (`GUAM_RESTAURANTS_CSP=report-only`, the default). It
allows our own files, PayPal (checkout), the Google Maps embed (frame), map tiles and
styles over HTTPS, MapLibre's `blob:` workers, and the analytics host when it is not
ours. WordPress core, WooCommerce and PayPal print inline scripts, so `script-src`
keeps `'unsafe-inline'`; the policy still blocks plugins (`object-src 'none'`),
`<base>` injection, framing by other sites and form posts to unknown hosts. Checked
locally on the home, directory, restaurant, map, policy, advertise and login pages:
no violations. After a week on the live site with no violations in the browser
console, set `GUAM_RESTAURANTS_CSP=enforce`. Extend it with the
`guam_restaurants_csp_directives` filter.

**Cached pages.** A page served from a page cache or CDN without PHP only has the
headers the server adds. So add the same headers at the server or CDN, then set
`GUAM_RESTAURANTS_SECURITY_HEADERS=0` if you want a single source:

- **Kinsta / managed hosts:** ask support to add them, or use the host's header tool.
- **Cloudflare:** Rules > Transform Rules > Modify Response Header, one rule per header.
- **Apache** (with the owned WP .htaccess Editor plugin, above `# BEGIN WordPress`):

```apache
<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set X-Frame-Options "SAMEORIGIN"
  Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), usb=(), payment=(self \"https://www.paypal.com\")"
  Header always set Cross-Origin-Opener-Policy "same-origin-allow-popups"
  Header always set X-Permitted-Cross-Domain-Policies "none"
  # After HTTPS works on every host name:
  # Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
  Header unset X-Powered-By
</IfModule>
<Files xmlrpc.php>
  Require all denied
</Files>
```

- **nginx:** the same values with `add_header ... always;` and
  `location = /xmlrpc.php { deny all; }`. Set `expose_php = Off` in PHP either way.

## 4. Login protection and two-factor sign-in

**Rate limiting: LoginPress Pro (owned), Limit Login Attempts add-on.** Its readme
(6.2.5, checked 2026-10-10) lists Limit Login Attempts, Hide Login and reCAPTCHA /
Turnstile as Pro add-ons. Settings: 4 attempts, 20-minute lockout, lockouts
lengthen after repeats; turn on Cloudflare Turnstile (free) for the login form.
Its readme says tested up to WordPress 7.0, so test it on staging with 7.1 first. If it
misbehaves, **MalCare** (owned) has CAPTCHA login protection in its free tier.

**MalCare (owned), free tier:** firewall and malware scanning (readme 6.72, tested up to
7.1). Its backups and "hardening" are paid; we do not rely on them (backups:
docs/backups.md; hardening: §2). On Kinsta or another host with its own firewall, keep
MalCare for scanning and leave its firewall in monitor mode if the host asks.

**Two-factor: the Two Factor plugin** (`two-factor`, 0.17.0, GPL, by WordPress
contributors, tested up to 7.1). None of the owned plugins has documented two-factor:
LoginPress does not list it, and MalCare's changelog mentions email codes without
saying which tier. Two Factor supports authenticator apps (TOTP), email codes and
backup codes. It has no enforcement setting of its own, so the plugin adds one:

1. Install and activate Two Factor on staging, then production.
2. Each administrator: Users > Profile > Two-Factor Options, turn on "Authenticator
   app", scan the code, turn on "Backup codes" and store them in a password manager.
3. Set `define( 'GUAM_RESTAURANTS_REQUIRE_ADMIN_2FA', true );`. From then on an
   administrator without two-factor is sent to their profile on every wp-admin screen
   until they turn it on (`Security\Two_Factor_Policy`). AJAX, cron and WP-CLI are not
   affected, so nobody is locked out of the server.
4. Lost phone: sign in with a backup code; failing that, from SSH:
   `wp user meta delete <user> _two_factor_enabled_providers`.

## 5. CDN, real client IPs and rate limits

- Behind Cloudflare set `GUAM_RESTAURANTS_CLIENT_IP_HEADER=HTTP_CF_CONNECTING_IP`.
  Other CDNs: the header they document (for example `HTTP_X_REAL_IP`). Only set it when
  the origin accepts traffic from the CDN alone (Cloudflare: "Authenticated Origin
  Pulls" or the host's own Cloudflare integration, as on Kinsta); otherwise anyone can
  fake the header and dodge the limits.
- Cloudflare rate limiting rule (free plan has one): path starts with
  `/wp-json/guam-restaurants/v1/` and method `POST`, 30 requests per 10 seconds per IP,
  block for 10 minutes. This covers the ad and listing beacons and all forms.
- The plugin's own limits (`Security\Rate_Limit`, per hashed visitor IP): reviews and
  photos 3 and 5 an hour, listing views 60, listing beacon 120 and ad beacon 240
  requests an hour. Without `GUAM_RESTAURANTS_CLIENT_IP_HEADER` behind a CDN, all
  visitors share these, so set it before launch.
- Cloudflare WAF managed rules on, "Bot Fight Mode" off (it blocks the beacon's
  `sendBeacon` calls from some browsers).

## 6. Dependency audit, 2026-10-10

| Component | Version | Result |
|---|---|---|
| Composer dev tools (PHPCS, WPCS, PHPUnit, wp-phpunit) | `composer.lock` | `composer audit`: no advisories. Not deployed (dev only). |
| npm | none | The project has no `package.json`. |
| MapLibre GL JS (vendored) | 5.24.0 → **6.13.0** | 5.x affected by CVE-2026-85061 (critical XSS in `DOM.sanitize()`; GitLab advisory DB and NVD). Fixed in 6.4.1, no 5.x fix. Updated; version 6 ships only as ES modules, so `assets/site/map.js` now uses `import()`. Checked in Chromium: map page clusters, popups and a restaurant page marker work, no console errors. |
| WooCommerce | 11.2.1 pinned for local tests | Production installs the current release from wp-admin. |
| WordPress core | 7.1.3 at the time of the plugin research | Keep minor auto-updates on. |
| Owned and third-party plugins | live site | Check monthly: `wp plugin list --update=available`, and MalCare's vulnerability alerts. Update on staging first. |

Re-run: `composer audit`; compare `assets/vendor/maplibre-gl/README.md` with
[npm](https://www.npmjs.com/package/maplibre-gl) and the
[GitLab advisory page](https://advisories.gitlab.com/pkg/npm/maplibre-gl/).

## 7. Incident basics

- Who decides and who notifies: [owner name]. Guam's breach law (9 GCA Ch. 48) only
  covers ID numbers and financial account numbers, which the site does not store
  (PayPal holds payment data), but notify affected users of any account breach anyway.
- First steps: put the site in maintenance mode, change all administrator passwords and
  the database password, rotate salts and API keys, restore from a clean backup
  (docs/backups.md), then find the cause before reopening.
