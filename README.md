# cPanel Traffic Stats for WordPress

See your website traffic from cPanel's **AWStats** and **Webalizer** right inside the WordPress dashboard, with no tracking code, no cookies and no third-party service. The numbers come from your server's own access logs, the same ones cPanel already processes.

![Traffic Stats report](docs/screenshot-report.png)

## Features

- **Dashboard → Traffic Stats** report page with a month picker:
  - Unique visitors, visits, page views, hits and bandwidth, with change against the previous month (in-progress months are compared with the same number of days)
  - Daily traffic chart (visits / page views / hits) and page views by hour
  - Top pages, referring sites, search engines, search phrases, countries, browsers, operating systems, robots, HTTP errors and 404 URLs
  - Webalizer daily chart and 12-month history table
- **Dashboard widget** with this month's summary and a daily sparkline
- Two ways to get the data:
  - **Local files**: WordPress runs on the same cPanel account, so the plugin reads `~/tmp/awstats` and `~/tmp/webalizer` directly. The home folder is detected automatically.
  - **cPanel API token**: connects to any cPanel server over UAPI (`Fileman::list_files` / `Fileman::get_file_content`) so WordPress can live somewhere else.
- Combines HTTP and HTTPS stats (cPanel stores them separately), or shows either one
- Results are cached (default 60 minutes) with a **Refresh** button
- **Test connection** button on the settings page that tells you exactly what's wrong, and lists the domains that do have stats if yours isn't found
- API token stored encrypted (AES-256 with your site's salts)
- Choose who can see the stats (admins, editors, or authors)
- No JavaScript libraries or external requests; charts are inline SVG

<img src="docs/screenshot-widget.png" alt="Dashboard widget" width="360">

## Requirements

- WordPress 5.8+ and PHP 7.4+
- A cPanel hosting account with AWStats and/or Webalizer turned on (cPanel → **Metrics → Metrics Editor**). cPanel processes the logs about once a day.

## Installation

1. Download this repository as a ZIP (**Code → Download ZIP**) and upload it in **Plugins → Add New → Upload Plugin**, or copy the folder into `wp-content/plugins/cpanel-traffic-stats`.
2. Activate **cPanel Traffic Stats**.
3. Go to **Settings → cPanel Traffic Stats**, check the domain and choose a source (below).
4. Click **Run test**, then open **Dashboard → Traffic Stats**.

### Option A: local files (same server)

If WordPress is installed in the cPanel account whose stats you want (for example `/home/username/public_html`), leave the source on **Read the stats files on this server**. The plugin finds `/home/username` automatically. If it can't (for example with `open_basedir` restrictions), enter the home folder yourself.

### Option B: cPanel API token (any server)

1. In cPanel, open **Security → Manage API Tokens** and create a token. Give it an expiry date if you like.
2. In the plugin settings choose **Connect to cPanel with an API token**, and enter:
   - **cPanel server**: the host you log in to cPanel with, without `https://` (e.g. `server.example.com` or `example.com`)
   - **Port**: `2083` (the default HTTPS cPanel port)
   - **cPanel username** and the **API token**
3. Save, then **Run test**.

The token gives full access to the cPanel account, so treat it like a password. The plugin only uses it to read files under `~/tmp/awstats` and `~/tmp/webalizer*`.

## Where cPanel keeps the stats

| Program | HTTP | HTTPS |
| --- | --- | --- |
| AWStats | `~/tmp/awstats/awstatsMMYYYY.domain.txt` | `~/tmp/awstats/ssl/awstatsMMYYYY.domain.txt` |
| Webalizer | `~/tmp/webalizer/webalizer.hist`, `usage_YYYYMM.html` | `~/tmp/webalizerssl/…` |

The plugin parses AWStats' data files directly (the same database AWStats uses to draw its own pages), and Webalizer's `webalizer.hist` monthly totals plus the daily table in `usage_YYYYMM.html`.

## Notes and limits

- When HTTP and HTTPS are combined, unique visitors are added together, so someone who visited over both counts twice. Visits, page views, hits and bandwidth add up correctly.
- AWStats counts robots separately; "page views" are human traffic only, the robots table and "not counted" line show the rest.
- Tables keep the top 100 rows of each list to keep the cache small.
- Stats are only as fresh as cPanel's last log run (shown on the report).

## Development

The parsers have no WordPress dependencies and come with sample data:

```sh
php tests/parser-test.php
```

`tests/fixtures/home` mimics a cPanel home folder, so you can also point the plugin's **cPanel home folder** setting at it on a local WordPress install to see the report with sample data.

### Filters

- `cts_view_capability` – change the capability needed to view stats.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
