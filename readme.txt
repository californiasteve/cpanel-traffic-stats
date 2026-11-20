=== cPanel Traffic Stats ===
Contributors: californiasteve
Tags: stats, analytics, awstats, webalizer, cpanel
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Shows your website traffic from cPanel's AWStats and Webalizer in the WordPress dashboard. No tracking code, no cookies.

== Description ==

cPanel already turns your server's access logs into traffic stats with AWStats and Webalizer. This plugin brings those numbers into WordPress:

* A Traffic Stats report under Dashboard with visitors, visits, page views, hits and bandwidth, daily and hourly charts, top pages, referrers, search engines, search phrases, countries, browsers, operating systems, robots and errors.
* A Dashboard widget with this month's summary.
* Download reports as PDF or CSV for any period.
* Scheduled email reports: daily, weekly, monthly on a date, or monthly on a weekday such as the second Saturday, with PDF/CSV attachments.
* Reads the stats files directly when WordPress is on the same cPanel account, or connects to cPanel with an API token from anywhere.
* Combines HTTP and HTTPS stats.
* Caches results, with a Refresh button and a connection test.

== Installation ==

1. Upload the plugin folder to /wp-content/plugins/ or install the ZIP from Plugins > Add New.
2. Activate it.
3. Go to Traffic Stats > Settings in the admin menu, check the domain, choose where to read the stats from, and click Run test.
4. Open Traffic Stats > Report.

To use an API token, create one in cPanel under Security > Manage API Tokens.

== Frequently Asked Questions ==

= The test says no data files were found =

Turn on AWStats or Webalizer in cPanel (Metrics > Metrics Editor) and wait for cPanel's daily log run. Check the domain matches the one in cPanel; the test lists the domains that do have AWStats data.

= Why are unique visitors higher than in AWStats? =

cPanel keeps HTTP and HTTPS stats separately. When you combine them, unique visitors are added together. Choose "HTTPS only" in the settings to match a single AWStats report.

== Changelog ==

= 1.2.0 =
* The plugin now has its own Traffic Stats menu in the admin sidebar, with Report, Email Reports and Settings underneath. Old links redirect to the new pages.

= 1.1.0 =
* Download reports as PDF or CSV (viewed month, preset periods, or custom dates).
* Scheduled email reports under Traffic Stats > Email Reports: daily, weekly on chosen days, monthly on a date or the last day, or monthly on the Nth weekday (e.g. second Saturday, last Friday). Choose time, recipients, report period and attachments.

= 1.0.1 =
* Connection test offers a "Use this domain" button when stats are filed under a different domain (common on Bluehost, where the main site uses the built-in mybluehost.me domain).

= 1.0.0 =
* First release.
