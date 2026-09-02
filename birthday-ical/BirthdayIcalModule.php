<?php

/**
 * Birthday iCalendar feed for webtrees 2.2.x
 *
 * Gives every logged-in member a private, subscribable .ics feed of upcoming
 * birthdays, in one of two modes:
 *   a) "My relatives"  – people within N relationship steps of the member's own record
 *   b) "All living"    – every living individual the member is allowed to see
 *
 * Optionally pushes the same events into a CalDAV server (e.g. Radicale).
 *
 * Licence: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace BirthdayIcal;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Date\GregorianDate;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\Http\Exceptions\HttpNotFoundException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\User;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Sabre\VObject\Component\VCalendar;

use function bin2hex;
use function e;
use function json_decode;
use function json_encode;
use function random_bytes;
use function redirect;
use function response;
use function route;
use function strip_tags;

class BirthdayIcalModule extends AbstractModule implements ModuleCustomInterface, ModuleConfigInterface, ModuleMenuInterface, ModuleGlobalInterface
{
    use ModuleCustomTrait;
    use ModuleGlobalTrait;
    use ModuleConfigTrait;
    use ModuleMenuTrait;

    public const MODE_RELATIVES = 'relatives';
    public const MODE_ALL       = 'all';

    // Birthdays are short timed events at this local (floating) time of day.
    private const EVENT_START_TIME = '090000';
    private const EVENT_END_TIME   = '090100';

    // Per-user preference keys (stored in wt_user_setting)
    private const PREF_TOKEN        = 'birthday-ical-token';
    private const PREF_MODE         = 'birthday-ical-mode';
    private const PREF_DEPTH        = 'birthday-ical-depth';
    private const PREF_ALARM        = 'birthday-ical-alarm';
    private const PREF_CALDAV_URL   = 'birthday-ical-caldav-url';
    private const PREF_CALDAV_USER  = 'birthday-ical-caldav-user';
    private const PREF_CALDAV_PASS  = 'birthday-ical-caldav-pass';

    // Module-wide (admin) preference keys
    private const SETTING_DEFAULT_MODE  = 'default_mode';
    private const SETTING_DEFAULT_DEPTH = 'default_depth';
    private const SETTING_MAX_DEPTH     = 'max_depth';
    private const SETTING_DEFAULT_ALARM = 'default_alarm';
    private const SETTING_ALLOW_ALL     = 'allow_all';
    private const SETTING_ALLOW_CALDAV  = 'allow_caldav';

    private const ROUTE_PAGE = '/tree/{tree}/birthday-ical';
    private const ROUTE_FEED = '/tree/{tree}/birthday-ical/feed/{token}';
    private const ROUTE_PUSH = '/tree/{tree}/birthday-ical/push/{token}';

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');

        $router = Registry::routeFactory()->routeMap();
        $router->get(self::class . '::page', self::ROUTE_PAGE, new ClosureHandler($this->getPage(...)));
        $router->post(self::class . '::save', self::ROUTE_PAGE, new ClosureHandler($this->postPage(...)));
        $router->get(self::class . '::feed', self::ROUTE_FEED, new ClosureHandler($this->getFeed(...)));
        $router->get(self::class . '::push', self::ROUTE_PUSH, new ClosureHandler($this->getPush(...)));
    }

    public function title(): string
    {
        return I18N::translate('Birthday calendar');
    }

    public function description(): string
    {
        return I18N::translate('A subscribable iCalendar feed of upcoming birthdays, per member, optionally pushed to a CalDAV server.');
    }

    public function customModuleAuthorName(): string
    {
        return 'Custom';
    }

    public function customModuleVersion(): string
    {
        return '1.0.11';
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public function defaultMenuOrder(): int
    {
        return 99;
    }

    /**
     * Menu icon: a small birthday-cake SVG, masked so it takes the theme's link colour.
     */
    public function headContent(): string
    {
        $svg = 'data:image/svg+xml;utf8,'
            . rawurlencode(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor">'
                . '<path d="M12 6a2 2 0 0 0 2-2c0-.38-.1-.73-.29-1.03L12 0l-1.71 2.97c-.19.3-.29.65-.29 1.03a2 2 0 0 0 2 2zm4.6 9.99l-1.07-1.07-1.08 1.07c-1.3 1.3-3.58 1.31-4.89 0l-1.07-1.07-1.09 1.07C6.75 16.64 5.88 17 4.96 17c-.73 0-1.4-.23-1.96-.61V21c0 .55.45 1 1 1h16c.55 0 1-.45 1-1v-4.61c-.56.38-1.23.61-1.96.61-.92 0-1.79-.36-2.44-1.01zM18 9h-5V7h-2v2H6c-1.66 0-3 1.34-3 3v1.46c0 .85.5 1.67 1.31 1.94.73.24 1.52.06 2.03-.46l2.14-2.13 2.13 2.13c.86.86 2.36.86 3.22 0l2.14-2.13 2.13 2.13c.51.52 1.31.7 2.03.46.81-.27 1.31-1.09 1.31-1.94V12c0-1.66-1.34-3-3-3z"/>'
                . '</svg>'
            );

        return '<style>'
            . '.menu-birthday-ical .nav-link::before{'
            . 'content:"";display:inline-block;width:1em;height:1em;margin-right:.35em;vertical-align:-0.125em;'
            . 'background-color:currentColor;'
            . '-webkit-mask:url(\'' . $svg . '\') no-repeat center/contain;'
            . 'mask:url(\'' . $svg . '\') no-repeat center/contain;'
            . '}'
            . '</style>';
    }

    public function getMenu(Tree $tree): Menu|null
    {
        if (!Auth::check()) {
            return null;
        }

        return new Menu(
            $this->title(),
            route(self::class . '::page', ['tree' => $tree->name()]),
            'menu-birthday-ical'
        );
    }

    // -------------------------------------------------------------------------
    // Admin configuration
    // -------------------------------------------------------------------------

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        return $this->viewResponse($this->name() . '::config', [
            'title'         => $this->title(),
            'default_mode'  => $this->getPreference(self::SETTING_DEFAULT_MODE, self::MODE_RELATIVES),
            'default_depth' => (int) $this->getPreference(self::SETTING_DEFAULT_DEPTH, '3'),
            'max_depth'     => (int) $this->getPreference(self::SETTING_MAX_DEPTH, '5'),
            'default_alarm' => (int) $this->getPreference(self::SETTING_DEFAULT_ALARM, '1'),
            'allow_all'     => $this->getPreference(self::SETTING_ALLOW_ALL, '1') === '1',
            'allow_caldav'  => $this->getPreference(self::SETTING_ALLOW_CALDAV, '1') === '1',
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        // NB: webtrees' Validator accumulates rules, so use a fresh one per parameter.
        $mode = Validator::parsedBody($request)->isInArray([self::MODE_RELATIVES, self::MODE_ALL])->string('default_mode');

        $this->setPreference(self::SETTING_DEFAULT_MODE, $mode);
        $this->setPreference(self::SETTING_DEFAULT_DEPTH, (string) max(1, min(10, Validator::parsedBody($request)->integer('default_depth'))));
        $this->setPreference(self::SETTING_MAX_DEPTH, (string) max(1, min(10, Validator::parsedBody($request)->integer('max_depth'))));
        $this->setPreference(self::SETTING_DEFAULT_ALARM, (string) max(0, min(30, Validator::parsedBody($request)->integer('default_alarm'))));
        $this->setPreference(self::SETTING_ALLOW_ALL, Validator::parsedBody($request)->boolean('allow_all', false) ? '1' : '0');
        $this->setPreference(self::SETTING_ALLOW_CALDAV, Validator::parsedBody($request)->boolean('allow_caldav', false) ? '1' : '0');

        FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        return redirect($this->getConfigLink());
    }

    // -------------------------------------------------------------------------
    // Member page: choose mode, get the feed URL, configure CalDAV push
    // -------------------------------------------------------------------------

    public function getPage(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Auth::user();

        if (!Auth::check()) {
            throw new HttpAccessDeniedException();
        }

        $token = $user->getPreference(self::PREF_TOKEN);
        if ($token === '') {
            $token = $this->newToken();
            $user->setPreference(self::PREF_TOKEN, $token);
        }

        $feed_url = route(self::class . '::feed', ['tree' => $tree->name(), 'token' => $token]);
        $push_url = route(self::class . '::push', ['tree' => $tree->name(), 'token' => $token]);

        $own_xref = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF)
            ?: $tree->getUserPreference($user, UserInterface::PREF_TREE_DEFAULT_XREF);
        $own_individual = $own_xref !== '' ? Registry::individualFactory()->make($own_xref, $tree) : null;

        return $this->viewResponse($this->name() . '::page', [
            'title'          => $this->title(),
            'tree'           => $tree,
            'module'         => $this,
            'feed_url'       => $feed_url,
            'webcal_url'     => preg_replace('/^https?:/', 'webcal:', $feed_url),
            'push_url'       => $push_url,
            'mode'           => $this->userMode($user),
            'depth'          => $this->userDepth($user),
            'max_depth'      => (int) $this->getPreference(self::SETTING_MAX_DEPTH, '5'),
            'alarm'          => $this->userAlarm($user),
            'allow_all'      => $this->getPreference(self::SETTING_ALLOW_ALL, '1') === '1',
            'allow_caldav'   => $this->getPreference(self::SETTING_ALLOW_CALDAV, '1') === '1',
            'caldav_url'     => $user->getPreference(self::PREF_CALDAV_URL),
            'caldav_user'    => $user->getPreference(self::PREF_CALDAV_USER),
            'caldav_has_pw'  => $user->getPreference(self::PREF_CALDAV_PASS) !== '',
            'own_individual' => $own_individual,
            'count'          => $this->birthdayFacts($tree, $user)->count(),
        ]);
    }

    public function postPage(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Auth::user();

        if (!Auth::check()) {
            throw new HttpAccessDeniedException();
        }

        $action = Validator::parsedBody($request)->string('action', 'save');

        switch ($action) {
            case 'regenerate':
                $user->setPreference(self::PREF_TOKEN, $this->newToken());
                FlashMessages::addMessage(I18N::translate('A new private feed address has been generated. Re-subscribe on your devices.'), 'info');
                break;

            case 'push':
                $result = $this->pushToCaldav($tree, $user);
                FlashMessages::addMessage($result['message'], $result['ok'] ? 'success' : 'danger');
                break;

            default:
                $mode = Validator::parsedBody($request)->isInArray([self::MODE_RELATIVES, self::MODE_ALL])->string('mode');
                if ($mode === self::MODE_ALL && $this->getPreference(self::SETTING_ALLOW_ALL, '1') !== '1') {
                    $mode = self::MODE_RELATIVES;
                }
                $max_depth = (int) $this->getPreference(self::SETTING_MAX_DEPTH, '5');

                $user->setPreference(self::PREF_MODE, $mode);
                $user->setPreference(self::PREF_DEPTH, (string) max(1, min($max_depth, Validator::parsedBody($request)->integer('depth', 3))));
                $user->setPreference(self::PREF_ALARM, (string) max(0, min(30, Validator::parsedBody($request)->integer('alarm', 1))));

                if ($this->getPreference(self::SETTING_ALLOW_CALDAV, '1') === '1') {
                    $user->setPreference(self::PREF_CALDAV_URL, trim(Validator::parsedBody($request)->string('caldav_url', '')));
                    $user->setPreference(self::PREF_CALDAV_USER, trim(Validator::parsedBody($request)->string('caldav_user', '')));
                    $pass = Validator::parsedBody($request)->string('caldav_pass', '');
                    if ($pass !== '') {
                        $user->setPreference(self::PREF_CALDAV_PASS, $pass);
                    }
                    if (Validator::parsedBody($request)->boolean('caldav_clear_pass', false)) {
                        $user->setPreference(self::PREF_CALDAV_PASS, '');
                    }
                }

                FlashMessages::addMessage(I18N::translate('Your birthday calendar settings have been saved.'), 'success');
        }

        return redirect(route(self::class . '::page', ['tree' => $tree->name()]));
    }

    // -------------------------------------------------------------------------
    // Feed: /tree/{tree}/birthday-ical/feed/{token}  (no login needed)
    // -------------------------------------------------------------------------

    public function getFeed(ServerRequestInterface $request): ResponseInterface
    {
        $tree  = Validator::attributes($request)->tree();
        $token = Validator::attributes($request)->string('token');
        $user  = $this->userByToken($token);

        if ($user === null) {
            throw new HttpNotFoundException();
        }

        $ics = $this->runAs($user, fn (): string => $this->buildCalendar($tree, $user)->serialize());

        return response($ics)
            ->withHeader('content-type', 'text/calendar; charset=utf-8')
            ->withHeader('content-disposition', 'inline; filename="birthdays-' . $tree->name() . '.ics"')
            ->withHeader('cache-control', 'private, max-age=3600');
    }

    // Cron-friendly: /tree/{tree}/birthday-ical/push/{token}
    public function getPush(ServerRequestInterface $request): ResponseInterface
    {
        $tree  = Validator::attributes($request)->tree();
        $token = Validator::attributes($request)->string('token');
        $user  = $this->userByToken($token);

        if ($user === null) {
            throw new HttpNotFoundException();
        }

        $result = $this->runAs($user, fn (): array => $this->pushToCaldav($tree, $user));

        return response($result['message'] . "\n", $result['ok'] ? 200 : 502)
            ->withHeader('content-type', 'text/plain; charset=utf-8');
    }

    // -------------------------------------------------------------------------
    // Core: which birthdays does this user get?
    // -------------------------------------------------------------------------

    /**
     * @return \Illuminate\Support\Collection<int,Fact> birth facts, one per individual
     */
    public function birthdayFacts(Tree $tree, UserInterface $user): \Illuminate\Support\Collection
    {
        $access_level = Auth::accessLevel($tree, $user);

        $individuals = $this->userMode($user) === self::MODE_RELATIVES
            ? $this->relatives($tree, $user, $access_level)
            : $this->allLiving($tree, $access_level);

        return $individuals
            ->filter(fn (Individual $i): bool => $i->canShow($access_level) && $i->canShowName($access_level) && !$i->isDead())
            ->map(fn (Individual $i): ?Fact => $this->exactBirthFact($i))
            ->filter()
            ->values();
    }

    /**
     * Mode (b): every individual the user may see, pre-filtered in SQL to those with a
     * day+month Gregorian birth date so we don't instantiate the whole tree.
     */
    private function allLiving(Tree $tree, int $access_level): \Illuminate\Support\Collection
    {
        return DB::table('individuals')
            ->join('dates', static function ($join): void {
                $join->on('d_gid', '=', 'i_id')->on('d_file', '=', 'i_file');
            })
            ->where('i_file', '=', $tree->id())
            ->where('d_fact', '=', 'BIRT')
            ->where('d_type', '=', '@#DGREGORIAN@')
            ->where('d_day', '>', 0)
            ->where('d_mon', '>', 0)
            ->select(['individuals.*'])
            ->distinct()
            ->get()
            ->map(Registry::individualFactory()->mapper($tree))
            ->filter();
    }

    /**
     * Mode (a): breadth-first walk from the user's own record through
     * parents, siblings, spouses and children, up to N steps.
     */
    private function relatives(Tree $tree, UserInterface $user, int $access_level): \Illuminate\Support\Collection
    {
        $xref = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF)
            ?: $tree->getUserPreference($user, UserInterface::PREF_TREE_DEFAULT_XREF);

        $start = $xref !== '' ? Registry::individualFactory()->make($xref, $tree) : null;
        if ($start === null) {
            return new \Illuminate\Support\Collection();
        }

        $depth   = $this->userDepth($user);
        $seen    = [$start->xref() => $start];
        $frontier = [$start];

        for ($step = 0; $step < $depth && $frontier !== []; $step++) {
            $next = [];
            foreach ($frontier as $individual) {
                $neighbours = [];

                foreach ($individual->childFamilies($access_level) as $family) {
                    foreach ($family->spouses($access_level) as $parent) {
                        $neighbours[] = $parent;
                    }
                    foreach ($family->children($access_level) as $sibling) {
                        $neighbours[] = $sibling;
                    }
                }
                foreach ($individual->spouseFamilies($access_level) as $family) {
                    foreach ($family->spouses($access_level) as $spouse) {
                        $neighbours[] = $spouse;
                    }
                    foreach ($family->children($access_level) as $child) {
                        $neighbours[] = $child;
                    }
                }

                foreach ($neighbours as $n) {
                    if (!isset($seen[$n->xref()])) {
                        $seen[$n->xref()] = $n;
                        $next[] = $n;
                    }
                }
            }
            $frontier = $next;
        }

        return new \Illuminate\Support\Collection(array_values($seen));
    }

    /** iCalendar needs an exact Gregorian day. */
    private function exactBirthFact(Individual $individual): ?Fact
    {
        foreach ($individual->facts(['BIRT'], true) as $fact) {
            $date = $fact->date();
            if (
                $date->isOK()
                && $date->minimumDate() instanceof GregorianDate
                && $date->minimumJulianDay() === $date->maximumJulianDay()
                && $date->minimumDate()->day() > 0
                && $date->minimumDate()->month() > 0
            ) {
                return $fact;
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // iCalendar generation
    // -------------------------------------------------------------------------

    public function buildCalendar(Tree $tree, UserInterface $user): VCalendar
    {
        $alarm_days = $this->userAlarm($user);
        $host       = parse_url(route(self::class . '::page', ['tree' => $tree->name()]), PHP_URL_HOST) ?: 'webtrees';

        $vcalendar = new VCalendar();
        $vcalendar->PRODID = '-//webtrees//birthday-ical//EN';
        $vcalendar->add('X-WR-CALNAME', $tree->title() . ' — ' . I18N::translate('Birthdays'));
        $vcalendar->add('REFRESH-INTERVAL', 'P1D', ['VALUE' => 'DURATION']);
        $vcalendar->add('X-PUBLISHED-TTL', 'P1D');

        foreach ($this->birthdayFacts($tree, $user) as $fact) {
            $this->addEvent($vcalendar, $fact, $alarm_days, $host, $tree);
        }

        return $vcalendar;
    }

    private function eventUid(Fact $fact, Tree $tree, string $host): string
    {
        return 'wt-birthday-' . $tree->id() . '-' . $fact->record()->xref() . '@' . $host;
    }

    private function addEvent(VCalendar $vcalendar, Fact $fact, int $alarm_days, string $host, Tree $tree): void
    {
        /** @var Individual $individual */
        $individual = $fact->record();
        $date       = $fact->date()->minimumDate();
        $name       = strip_tags($individual->fullName());
        $year       = $date->year();

        $vevent = $vcalendar->add('VEVENT', [
            'UID'         => $this->eventUid($fact, $tree, $host),
            'SUMMARY'     => sprintf('🎂 %s', $name) . ($year > 0 ? sprintf(' (%d)', $year) : ''),
            'DESCRIPTION' => ($year > 0 ? I18N::translate('Born %s', $fact->date()->display()) . "\n" : '') . $individual->url(),
            'URL'         => $individual->url(),
            'RRULE'       => 'FREQ=YEARLY',
            'TRANSP'      => 'TRANSPARENT',
            'CATEGORIES'  => 'Birthday',
        ]);
        // Anchor the series at the current year rather than the birth year, so
        // calendars do not show decades of historical occurrences; the birth year
        // is carried in the summary instead. For 29 February, anchor at the most
        // recent leap year so the DTSTART is a valid date (the yearly RRULE then
        // fires only on leap years, as before).
        $start_year = (int) date('Y');
        if ($date->month() === 2 && $date->day() === 29) {
            while (!checkdate(2, 29, $start_year)) {
                $start_year--;
            }
        }
        $day = sprintf('%04d%02d%02d', $start_year, $date->month(), $date->day());
        // A short timed event rather than an all-day banner. The date-times are
        // FLOATING (no timezone suffix), so every device shows them at 09:00 in
        // its own local timezone.
        $vevent->add('DTSTART', $day . 'T' . self::EVENT_START_TIME);
        $vevent->add('DTEND', $day . 'T' . self::EVENT_END_TIME);
        // NB: do not add DTSTAMP here - sabre/vobject adds one automatically,
        // and a duplicate makes Radicale reject the event with HTTP 400.

        // The event starts at 09:00 local time, so triggers are simple day offsets:
        // 0 = remind at 09:00 on the day itself, N = 09:00 N days earlier.
        $vevent->add('VALARM', [
            'ACTION'      => 'DISPLAY',
            'TRIGGER'     => $alarm_days > 0 ? '-P' . $alarm_days . 'D' : 'PT0S',
            'DESCRIPTION' => $name,
        ]);
    }

    // -------------------------------------------------------------------------
    // CalDAV push (Radicale, Nextcloud, Baïkal, ...)
    // -------------------------------------------------------------------------

    /**
     * @return array{ok:bool,message:string}
     */
    private function pushToCaldav(Tree $tree, UserInterface $user): array
    {
        if ($this->getPreference(self::SETTING_ALLOW_CALDAV, '1') !== '1') {
            return ['ok' => false, 'message' => I18N::translate('CalDAV push is disabled by the administrator.')];
        }

        $base = rtrim($user->getPreference(self::PREF_CALDAV_URL), '/') . '/';
        $auth = $user->getPreference(self::PREF_CALDAV_USER) . ':' . $user->getPreference(self::PREF_CALDAV_PASS);

        if ($base === '/' || !preg_match('#^https?://#', $base)) {
            return ['ok' => false, 'message' => I18N::translate('No CalDAV collection URL configured.')];
        }

        $host      = parse_url($base, PHP_URL_HOST) ?: 'webtrees';
        $alarm     = $this->userAlarm($user);
        // Bookkeeping lives in module_setting (longtext), not user_setting (varchar 255),
        // and is keyed per user AND per tree.
        $uids_key  = 'caldav-uids-' . $user->id() . '-' . $tree->id();
        $previous  = json_decode($this->getPreference($uids_key, '[]'), true) ?: [];
        $current   = [];
        $created   = 0;
        $errors    = [];

        foreach ($this->birthdayFacts($tree, $user) as $fact) {
            $uid = $this->eventUid($fact, $tree, $host);
            $current[] = $uid;

            $vcal = new VCalendar();
            $vcal->PRODID = '-//webtrees//birthday-ical//EN';
            $this->addEvent($vcal, $fact, $alarm, $host, $tree);

            [$status, $detail] = $this->httpRequest('PUT', $base . rawurlencode($uid) . '.ics', $auth, $vcal->serialize());
            if ($status >= 200 && $status < 300) {
                $created++;
            } else {
                $errors[] = $uid . ' → HTTP ' . $status . ($detail !== '' ? ' (' . $detail . ')' : '');
            }
        }

        // Remove events that were pushed earlier but are no longer in scope
        $removed = 0;
        foreach (array_diff($previous, $current) as $stale) {
            [$status, $detail] = $this->httpRequest('DELETE', $base . rawurlencode($stale) . '.ics', $auth);
            if ($status === 404 || ($status >= 200 && $status < 300)) {
                $removed++;
            } else {
                $errors[] = $stale . ' (delete) → HTTP ' . $status . ($detail !== '' ? ' (' . $detail . ')' : '');
            }
        }

        $this->setPreference($uids_key, json_encode(array_values($current)));

        $message = I18N::translate('CalDAV push: %d events written, %d removed.', $created, $removed);
        if ($errors !== []) {
            $message .= ' ' . I18N::translate('Errors:') . ' ' . implode('; ', array_slice($errors, 0, 5));
        }

        return ['ok' => $errors === [], 'message' => $message];
    }

    /**
     * @return array{0:int,1:string} HTTP status and a short diagnostic (server response body or curl error)
     */
    private function httpRequest(string $method, string $url, string $auth, string $body = ''): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERPWD        => $auth,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: text/calendar; charset=utf-8'],
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $detail   = $response === false ? curl_error($ch) : trim(strip_tags((string) $response));
        curl_close($ch);

        return [$status, mb_substr($detail, 0, 200)];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function newToken(): string
    {
        return bin2hex(random_bytes(20));
    }

    private function userByToken(string $token): ?User
    {
        if (!preg_match('/^[0-9a-f]{40}$/', $token)) {
            return null;
        }

        $user_id = DB::table('user_setting')
            ->where('setting_name', '=', self::PREF_TOKEN)
            ->where('setting_value', '=', $token)
            ->value('user_id');

        return $user_id === null ? null : Registry::container()->get(UserService::class)->find((int) $user_id);
    }

    /**
     * Run a callback with Auth::user() resolving to $user, so that privacy,
     * fullName(), url() etc. behave as they would for that member.
     * The session login is discarded afterwards.
     */
    private function runAs(UserInterface $user, callable $callback): mixed
    {
        $previous = Session::get('wt_user');
        Session::put('wt_user', $user->id());
        try {
            return $callback();
        } finally {
            if ($previous === null) {
                Session::forget('wt_user');
            } else {
                Session::put('wt_user', $previous);
            }
        }
    }

    private function userMode(UserInterface $user): string
    {
        $mode = $user->getPreference(self::PREF_MODE) ?: $this->getPreference(self::SETTING_DEFAULT_MODE, self::MODE_RELATIVES);

        if ($mode === self::MODE_ALL && $this->getPreference(self::SETTING_ALLOW_ALL, '1') !== '1') {
            return self::MODE_RELATIVES;
        }

        return $mode;
    }

    private function userDepth(UserInterface $user): int
    {
        $max = (int) $this->getPreference(self::SETTING_MAX_DEPTH, '5');
        $val = (int) ($user->getPreference(self::PREF_DEPTH) ?: $this->getPreference(self::SETTING_DEFAULT_DEPTH, '3'));

        return max(1, min($max, $val));
    }

    private function userAlarm(UserInterface $user): int
    {
        $val = $user->getPreference(self::PREF_ALARM);

        return (int) ($val !== '' ? $val : $this->getPreference(self::SETTING_DEFAULT_ALARM, '1'));
    }
}
