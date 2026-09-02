# Birthday reminders from webtrees on your phone

A custom module for **webtrees 2.2.x**. Every member gets a private, subscribable
iCalendar (.ics) feed of upcoming birthdays with alarms, in one of two modes:

* **My relatives** — people within N steps of the member's own record
  (parents, siblings, spouse, children; then grandparents, cousins, in-laws…)
* **All living individuals I can see** — everyone in the tree the member is
  allowed to view, filtered by webtrees' own privacy and living/dead rules

Optionally the module **pushes the events into a CalDAV server** (Radicale,
Nextcloud, Baïkal…), so the calendar becomes a normal synced calendar.

## Install

1. Copy the `birthday-ical` folder into `webtrees/modules_v4/`.
2. Control panel → Modules → **Menus**: enable "Birthday calendar" (it adds a menu
   entry for logged-in members).
3. Control panel → Modules → "Birthday calendar" → preferences (defaults, maximum
   steps, whether members may pick "all living", whether CalDAV push is allowed).
4. Make sure each member's account is linked to their own individual record
   (Control panel → User administration → *Individual record*), otherwise the
   "My relatives" mode has nowhere to start.

Members then open the menu item, choose a mode, and copy their private feed URL.

## Getting it on a phone

**Simplest (no Radicale needed):** subscribe to the feed URL.
* iPhone/iPad: Settings → Calendar → Accounts → Add Account → Other → Add Subscribed Calendar.
* Android: ICSx⁵ (F-Droid/Play) subscribes to .ics URLs; reminders come from the phone.
* Google Calendar: "Other calendars → From URL" (alarms in the feed are ignored;
  set notifications in Google Calendar).

**Via Radicale:** on the member page fill in the CalDAV
section with the URL of an *existing* Radicale calendar collection, e.g.
`https://radicale.example.org/alice/birthdays/`, plus the Radicale username and
password, save, then press **Push to CalDAV now**. Each birthday becomes a
yearly-recurring event with an alarm; re-pushing updates in place and deletes
events that dropped out of scope (new death record, changed depth, etc.).

You can trigger a sync manually at any time with the **Push to CalDAV now**
button on the member page.

To keep Radicale current automatically, call the push URL shown on the page from
cron on any machine that can reach webtrees, e.g.

    0 3 * * * curl -fsS "https://your-webtrees/tree/TREE/birthday-ical/push/TOKEN" >/dev/null

Your phone then syncs from Radicale with DAVx⁵ (Android) or the built-in CalDAV
account type (iOS), and reminders fire from the phone's calendar.

If webtrees and Radicale run as containers on the same Docker network, the
CalDAV URL can be the internal one (`http://radicale:5232/alice/birthdays/`).

## Notes and limitations

* Only exact Gregorian birth dates (day + month known) become events; "ABT 1950"
  or "1950" alone cannot be placed on a calendar.
* "Living" uses webtrees' `isDead()` logic (death record, or older than the
  tree's *maximum alive age*).
* The feed URL contains a secret token; anyone with the URL sees those birthdays.
  Members can regenerate it from their page.
* The CalDAV password is stored in plain text in the webtrees database
  (per-user setting). Prefer an app-specific password or a dedicated Radicale user.
* The relatives walk goes through all family links equally (no distinction between
  blood relatives and in-laws). Depth 3 on a normal tree is ~50–150 people.
