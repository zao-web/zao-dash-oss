<?php

namespace App\Support\Demo;

/**
 * Deterministically anonymizes a tree of Inertia props for demo mode.
 *
 * The goal is believable fake data, not redaction: "Brightwave Labs", "$3,840",
 * "harper.quinn@northwind.io" — never asterisks. Every transformation is seeded
 * from a per-session seed combined with the original value, so:
 *
 *  - the same real value always maps to the same fake value within a session
 *    (the dashboard stays internally coherent across pages), and
 *  - money/hours scale by a single hidden linear factor, so sums still reconcile.
 *
 * Classification is key-aware with a conservative deny-list: identifiers, slugs,
 * dates, enums and booleans are never touched, because they drive routing and
 * layout. When a key is unrecognized the value is left alone.
 */
class DemoDataMasker
{
    private const DENY_EXACT = [
        'id', 'uuid', 'ulid', 'slug', 'token', 'password', 'remember_token',
        'key', 'secret', 'version', 'component', 'url', 'href', 'route', 'path',
        'sort', 'order', 'position', 'page', 'per_page', 'current_page', 'last_page',
        'from', 'to', 'links', 'color', 'colour', 'icon', 'locale', 'timezone',
        'currency', 'iso', 'sha', 'hash', 'ip', 'ip_address', 'user_agent',
        'status', 'type', 'role', 'stage', 'priority', 'state', 'kind',
        'category', 'visibility', 'level', 'permission', 'provider', 'driver',
        'lat', 'lng', 'latitude', 'longitude', 'percent', 'percentage', 'ratio',
        'margin', 'progress', 'score', 'rating', 'count', 'total_count',
    ];

    private const DENY_SUFFIX = [
        '_id', '_uuid', '_slug', '_token', '_key', '_at', '_url', '_count',
        '_type', '_status', '_role', '_color', '_percent', '_percentage',
        '_ratio', '_code', '_hash',
    ];

    /** @var array<int, string> Keys whose value is a company/org-style name. */
    private const KEY_COMPANY = [
        'company', 'company_name', 'client_name', 'project_name', 'vendor',
        'organization', 'organisation', 'account_name', 'business_name',
    ];

    /** @var array<int, string> Keys whose value is a person name. */
    private const KEY_PERSON = [
        'first_name', 'last_name', 'full_name', 'contact', 'contact_name',
        'assignee', 'assignee_name', 'author', 'author_name', 'owner_name',
        'lead_name', 'user_name', 'username', 'created_by_name', 'attendee',
    ];

    /** @var array<int, string> Keys that hold an email address. */
    private const KEY_EMAIL = [
        'email', 'email_address', 'contact_email', 'recipient', 'sender',
        'from_email', 'to_email', 'reply_to',
    ];

    /** @var array<int, string> Keys that hold a phone number. */
    private const KEY_PHONE = ['phone', 'phone_number', 'mobile', 'tel', 'fax', 'contact_phone'];

    /** @var array<int, string> Keys that hold a street address. */
    private const KEY_ADDRESS = ['address', 'street', 'address_line1', 'address_line2', 'street_address'];

    /** @var array<int, string> Monetary keys, scaled by a single hidden factor. */
    private const KEY_MONEY = [
        'amount', 'total', 'subtotal', 'grand_total', 'balance', 'revenue',
        'mrr', 'arr', 'income', 'expense', 'cost', 'price', 'unit_price',
        'rate', 'hourly_rate', 'budget', 'fee', 'amount_due', 'amount_paid',
        'paid', 'due', 'outstanding', 'value', 'salary', 'retainer_amount',
        'invoice_total', 'line_total', 'profit', 'spend', 'avg_value', 'ltv',
    ];

    /** @var array<int, string> Time/effort keys, scaled by a single hidden factor. */
    private const KEY_HOURS = [
        'hours', 'hours_logged', 'hours_used', 'hours_budgeted', 'hours_remaining',
        'estimated_hours', 'actual_hours', 'billable_hours', 'minutes', 'duration',
        'effort', 'time_spent',
    ];

    /** @var array<int, string> Free-text keys replaced with believable filler. */
    private const KEY_TEXT = [
        'title', 'subject', 'description', 'summary', 'body', 'message',
        'content', 'note', 'notes', 'headline', 'excerpt', 'comment',
        'bio', 'about', 'reason', 'details', 'narrative', 'caption', 'label',
    ];

    private const COMPANIES = [
        'Brightwave Labs', 'Northwind Trading', 'Cedar Peak Group', 'Lumen Collective',
        'Harborline Media', 'Stonefield Partners', 'Vireo Health', 'Meridian Outdoors',
        'Foxglove Studio', 'Tidewater Co', 'Ironwood Ventures', 'Saffron & Co',
        'Bluefin Digital', 'Anchorpoint Travel', 'Greywood Capital', 'Solstice Brands',
        'Kestrel Aviation', 'Maplewood Resorts', 'Driftwood Hospitality', 'Quill & Compass',
        'Verdant Spaces', 'Copperline Foods', 'Wndsong Tourism', 'Atlas Provisions',
        'Pinecrest Destinations', 'Halcyon Works', 'Riverstone Civic', 'Aureum Finance',
        'Marigold Markets', 'Beacon Hill Tourism', 'Cobalt & Cedar', 'Thornfield Group',
        'Silverline Events', 'Wayfarer Collective', 'Emberline Outdoors', 'Lantern Bay',
        'Crestview Municipality', 'Goldenrod Tourism', 'Slate Harbor', 'Vantage Point Co',
    ];

    private const FIRST_NAMES = [
        'Harper', 'Mateo', 'Priya', 'Declan', 'Noor', 'Silas', 'Imani', 'Rowan',
        'Yara', 'Quinn', 'Esme', 'Tariq', 'Lena', 'Cassian', 'Daria', 'Felix',
        'Maya', 'Idris', 'Wren', 'Soren', 'Anika', 'Cyrus', 'Talia', 'Emir',
        'Nadia', 'Bram', 'Saoirse', 'Hugo', 'Leila', 'Oscar',
    ];

    private const LAST_NAMES = [
        'Avila', 'Brennan', 'Castellano', 'Dunmore', 'Eklund', 'Fontaine', 'Garib',
        'Holloway', 'Ishikawa', 'Joubert', 'Kovac', 'Larkin', 'Mensah', 'Nakamura',
        'Okafor', 'Petrov', 'Quintero', 'Rosales', 'Sandoval', 'Thorne', 'Ueda',
        'Vance', 'Whitlock', 'Xiong', 'Yusuf', 'Zambrano', 'Ashford', 'Beaumont',
    ];

    private const EMAIL_DOMAINS = [
        'northwind.io', 'brightwave.co', 'cedarpeak.com', 'lumencollective.com',
        'harborline.media', 'stonefield.partners', 'vireo.health', 'meridian.co',
        'tidewater.co', 'ironwood.vc', 'bluefin.digital', 'greywood.capital',
    ];

    private const STREETS = [
        'Sycamore', 'Harbor', 'Kestrel', 'Maple', 'Cobalt', 'Marigold', 'Driftwood',
        'Lantern', 'Crestview', 'Slate', 'Vantage', 'Pinecrest', 'Riverstone', 'Beacon',
    ];

    private const STREET_TYPES = ['St', 'Ave', 'Blvd', 'Way', 'Ln', 'Rd', 'Ct'];

    private const TEXT_PHRASES = [
        'Quarterly roadmap alignment and milestone review',
        'Homepage redesign and component library cleanup',
        'Migrate legacy templates to the new block theme',
        'Draft the seasonal campaign brief for stakeholder sign-off',
        'Audit analytics tracking and reconcile conversion events',
        'Refine the booking flow and reduce checkout friction',
        'Plan content calendar for the upcoming launch window',
        'Resolve outstanding accessibility findings from the audit',
        'Coordinate photography assets for the destination pages',
        'Set up automated reporting for the leadership dashboard',
        'Review proposal scope and finalize the statement of work',
        'Optimize page performance ahead of the peak traffic season',
        'Sync on integration requirements with the partner team',
        'Prepare onboarding materials for the new account',
        'Triage support backlog and group recurring themes',
    ];

    public function __construct(private readonly string $seed) {}

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    public function maskProps(array $props): array
    {
        $masked = [];

        foreach ($props as $key => $value) {
            $masked[$key] = $this->walk($value, (string) $key);
        }

        return $masked;
    }

    private function walk(mixed $value, ?string $key): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $isPaginator = ! $isList
                && array_key_exists('current_page', $value)
                && array_key_exists('per_page', $value);

            $out = [];
            foreach ($value as $childKey => $childValue) {
                // A paginator's `total`/`count` are row counts, not money — leave them
                // so "Showing 1–25 of N" and page controls stay correct.
                if ($isPaginator && in_array($childKey, ['total', 'count'], true)) {
                    $out[$childKey] = $childValue;

                    continue;
                }

                $out[$childKey] = $this->walk(
                    $childValue,
                    $isList ? $key : (string) $childKey,
                );
            }

            return $out;
        }

        if ($key === null) {
            return $value;
        }

        return $this->transform($key, $value);
    }

    private function transform(string $key, mixed $value): mixed
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }

        $normalized = strtolower($key);

        if (is_string($value) && $this->looksLikeEmail($value)) {
            return $this->fakeEmail($value);
        }

        if ($this->isDenied($normalized)) {
            return $value;
        }

        return match (true) {
            in_array($normalized, self::KEY_EMAIL, true) && is_string($value) => $this->fakeEmail($value),
            in_array($normalized, self::KEY_PHONE, true) && is_string($value) => $this->fakePhone($value),
            in_array($normalized, self::KEY_ADDRESS, true) && is_string($value) => $this->fakeAddress($value),
            in_array($normalized, self::KEY_COMPANY, true) && is_string($value) => $this->pick(self::COMPANIES, $value),
            in_array($normalized, self::KEY_PERSON, true) && is_string($value) => $this->fakePerson($normalized, $value),
            $this->isMoneyKey($normalized) => $this->scaleNumeric($value, $this->moneyFactor()),
            in_array($normalized, self::KEY_HOURS, true) => $this->scaleNumeric($value, $this->hoursFactor()),
            $normalized === 'name' && is_string($value) => $this->fakeName($value),
            in_array($normalized, self::KEY_TEXT, true) && is_string($value) => $this->fakeText($value),
            default => $value,
        };
    }

    private function isDenied(string $key): bool
    {
        if (in_array($key, self::DENY_EXACT, true)) {
            return true;
        }

        foreach (self::DENY_SUFFIX as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isMoneyKey(string $key): bool
    {
        if (in_array($key, self::KEY_MONEY, true)) {
            return true;
        }

        foreach (['_amount', '_total', '_revenue', '_cost', '_price', '_rate', '_balance', '_fee'] as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function fakeName(string $original): string
    {
        return $this->looksLikePerson($original)
            ? $this->personName($original)
            : $this->pick(self::COMPANIES, $original);
    }

    private function fakePerson(string $key, string $original): string
    {
        if ($key === 'first_name') {
            return $this->pick(self::FIRST_NAMES, $original);
        }

        if ($key === 'last_name') {
            return $this->pick(self::LAST_NAMES, $original);
        }

        return $this->personName($original);
    }

    private function personName(string $original): string
    {
        return $this->pick(self::FIRST_NAMES, $original.'|first')
            .' '.$this->pick(self::LAST_NAMES, $original.'|last');
    }

    private function fakeEmail(string $original): string
    {
        [$localSeed, $domainSeed] = str_contains($original, '@')
            ? explode('@', strtolower($original), 2)
            : [$original, $original];

        $first = strtolower($this->pick(self::FIRST_NAMES, $localSeed.'|f'));
        $last = strtolower($this->pick(self::LAST_NAMES, $localSeed.'|l'));
        $domain = $this->pick(self::EMAIL_DOMAINS, $domainSeed);

        return "{$first}.{$last}@{$domain}";
    }

    private function fakePhone(string $original): string
    {
        $area = 200 + ($this->hash($original.'|a') % 800);
        $prefix = 200 + ($this->hash($original.'|p') % 800);
        $line = $this->hash($original.'|l') % 10000;

        return sprintf('(%03d) %03d-%04d', $area, $prefix, $line);
    }

    private function fakeAddress(string $original): string
    {
        $number = 100 + ($this->hash($original.'|n') % 9800);
        $street = $this->pick(self::STREETS, $original.'|s');
        $type = $this->pick(self::STREET_TYPES, $original.'|t');

        return "{$number} {$street} {$type}";
    }

    private function fakeText(string $original): string
    {
        return $this->pick(self::TEXT_PHRASES, $original);
    }

    /**
     * Scale a money/hours value by a single hidden linear factor so totals
     * still reconcile. Preserves int/float/numeric-string typing and rounding.
     */
    private function scaleNumeric(mixed $value, float $factor): mixed
    {
        if (is_int($value)) {
            return (int) round($value * $factor);
        }

        if (is_float($value)) {
            return round($value * $factor, 2);
        }

        if (is_string($value) && is_numeric($value)) {
            $decimals = str_contains($value, '.') ? strlen(explode('.', $value)[1]) : 0;

            return number_format((float) $value * $factor, $decimals, '.', '');
        }

        return $value;
    }

    private function moneyFactor(): float
    {
        return $this->factor('money', 0.55, 1.65);
    }

    private function hoursFactor(): float
    {
        return $this->factor('hours', 0.6, 1.5);
    }

    private function factor(string $salt, float $min, float $max): float
    {
        $fraction = ($this->hash($salt) % 1000) / 1000;

        return round($min + ($fraction * ($max - $min)), 4);
    }

    /**
     * @param  array<int, string>  $pool
     */
    private function pick(array $pool, string $salt): string
    {
        return $pool[$this->hash($salt) % count($pool)];
    }

    private function hash(string $salt): int
    {
        return (int) sprintf('%u', crc32($this->seed.'|'.$salt));
    }

    private function looksLikeEmail(string $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    private function looksLikePerson(string $value): bool
    {
        $value = trim($value);

        if (preg_match('/\b(inc|llc|ltd|co|corp|company|group|labs?|studio|partners|capital|ventures|media|agency|collective|destinations?|tourism|municipality|civic)\b/i', $value)) {
            return false;
        }

        $words = preg_split('/\s+/', $value) ?: [];

        return count($words) === 2
            && ctype_upper($words[0][0] ?? '')
            && ctype_upper($words[1][0] ?? '');
    }
}
