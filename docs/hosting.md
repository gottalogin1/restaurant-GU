# Hosting

Recommendation for where to run the production site. Prices were checked on 2026-10-09 on
each host's own pricing page, in US dollars, before tax. Hosts change prices often; re-check
before buying.

## What this site needs

- **Close to Guam and its visitors.** Readers are on Guam plus visitors from Japan, Korea,
  the Philippines and the US. A server in Tokyo or Taiwan is about 2,500 to 2,800 km from
  Guam; the US West Coast is nearly 10,000 km away. A CDN with a Guam edge does the rest:
  Cloudflare lists a data center in Hagatna, GU on its [network page](https://www.cloudflare.com/network/).
- **Daily backups with at least 14 days kept,** plus on-demand backups before imports and plugin
  updates.
- **Staging,** so plugin updates and import jobs (CLAUDE.md rule 9) are tried before production.
- **WP-CLI and SSH,** because imports run through WP-CLI.
- **Modest traffic at launch.** A new local directory is unlikely to pass 25,000 to 50,000
  visits a month in year one, so entry plans are enough. Upgrade when traffic data says so.

## Recommendation

**Kinsta, single-site plan, Tokyo data center.** About **$35/month** billed monthly, or
**$30/month** billed yearly ($350/year).

- Includes daily automatic backups kept 14 days (6-hourly backups are a $20/month add-on),
  one-click staging, a CDN with 260+ locations (125 GB/month), WP-CLI and SSH, and a
  Cloudflare-based firewall.
- Runs on Google Cloud, with Asia-Pacific data centers including Tokyo, Osaka and Taiwan
  (see Kinsta's [Osaka](https://kinsta.com/nl/changelog/datacenter-osaka-japan/) and
  [Taiwan](https://kinsta.com/changelog/c3d-taiwan/) changelog posts). The pricing page
  itself does not list locations, so confirm Tokyo is offered when creating the site.
- Kinsta's three single-site plans all cost the same and differ by limit: 20 GB bandwidth,
  35,000 visits, or 75,000 visits per month. Start on the 35,000-visit plan.

## Options compared

| Host and plan | Est. monthly cost | Backups | Staging | CDN | Nearest data center to Guam |
|---|---|---|---|---|---|
| **Kinsta** single site | $35 monthly, $30 yearly | Daily, 14 days | Yes | Included, 125 GB | Tokyo, Osaka, Taiwan |
| **Rocket.net** Starter | $30 monthly, $25 yearly | Daily, 30 days | Not stated on pricing or features page; ask before buying | Cloudflare Enterprise | Singapore (only Asian location found) |
| **WP Engine** Essential | from $28 (yearly billing, $336/yr) | Daily and on-demand, 30 to 60 days | Yes, plus dev environment | Cloudflare-powered, included | Tokyo or Taiwan, but marked as possibly needing upgraded pricing |
| **SiteGround** GrowBig | $29.99 at renewal ($4.99 intro) | Daily and on-demand | Yes | Included | Singapore |
| **Cloudways** DigitalOcean 2 GB | about $16 to $20 ($11 server + $4.99 Cloudflare Enterprise + backup storage at $0.033/GB) | Automated or on-demand, offsite | Yes | Cloudflare Enterprise add-on | Depends on cloud; DigitalOcean has Singapore |

Notes:

- **Rocket.net** is the strongest CDN option (Cloudflare Enterprise everywhere) and keeps
  backups longest, but its only Asian server is Singapore and staging was not confirmed.
- **WP Engine** has Tokyo and Taiwan but flags them as possibly limited to higher-priced plans,
  so the real cost for an Asian region may be above $28.
- **SiteGround's** low price is an introductory rate; the regular price applies at renewal.
  StartUp ($17.99 renewal) has no staging, so GrowBig is the minimum that fits.
- **Cloudways** is the cheapest but is closer to a managed server than managed WordPress:
  you choose the server size and handle more tuning. Good if the owner is comfortable with that.
- On any host with its own page cache (Kinsta, WP Engine, Rocket.net), keep W3 Total Cache's
  page cache off. See `docs/plugins.md`.

## Other monthly costs to budget

These are outside hosting but appear on the same bill sheet:

- Domain name, about $15 to $25 per year.
- Akismet for a commercial site (paid plan; personal keys are free only for non-commercial sites).
- Email sending for the newsletter (MailPoet free tier needs your own sending service).
- Optional: 6-hourly backups on Kinsta ($20/month) once imports run frequently.

## Sources

- Kinsta pricing: https://kinsta.com/pricing/
- WP Engine plans: https://wpengine.com/plans/ and data centers: https://wpengine.com/support/servers/
- SiteGround: https://www.siteground.com/wordpress-hosting.htm
- Cloudways: https://www.cloudways.com/en/pricing.php
- Rocket.net: https://rocket.net/pricing/ and https://rocket.net/blog/singapore-location-is-now-available/
- Cloudflare network: https://www.cloudflare.com/network/
