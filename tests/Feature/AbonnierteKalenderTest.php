<?php

use Illuminate\Support\Facades\Http;
use Peppermint\Calendar\Subscriptions\IcsFeed;
use Peppermint\Calendar\Subscriptions\Models\CalendarSubscription;
use Peppermint\Calendar\Subscriptions\Models\SubscribedEvent;
use Peppermint\Calendar\Subscriptions\SubscriptionSync;
use Peppermint\Calendar\Tests\Support\FeedAttrappe;

/*
| Abonnierte fremde Kalender (Bug #1081).
|
| Lagen bis zum 29.10.2026 als `App\Services\IcsParserService` im Peppermint
| Manager — 207 Zeilen, die kein anderes System hatte, obwohl ein Outlook-Feed
| ueberall derselbe ist. Beim Heben NEU geschrieben und nicht verschoben, denn
| in der alten Fassung steckte ein Fehler, der zusammen mit Erinnerungen teuer
| geworden waere. Er hat hier seinen eigenen Fall.
*/

beforeEach(function () {
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');

    FeedAttrappe::zuruecksetzen();

    /*
     * EINE Attrappe, die immer die AKTUELLE Antwort liefert — und nicht je
     * Test eine neue.
     *
     * Laravel haengt weitere `Http::fake()`-Aufrufe an die Liste an, und bei
     * gleichem Muster gewinnt die ERSTE. Wer den Feed zweimal verschieden
     * antworten lassen will, bekommt sonst zweimal dieselbe Antwort — und das
     * sieht danach aus wie ein Fehler im Abgleich. Genau darauf bin ich beim
     * Schreiben dieser Reihe hereingefallen: drei rote Tests, eine Ursache,
     * und die lag in der Attrappe.
     */
    Http::fake(fn () => Http::response(FeedAttrappe::$inhalt, FeedAttrappe::$status));
});

function abo(array $felder = []): CalendarSubscription
{
    return CalendarSubscription::create(array_merge([
        'user_id' => 7,
        'name' => 'Outlook',
        'url' => 'https://outlook.example.test/feed.ics',
        'is_active' => true,
    ], $felder));
}

/** Ein ICS-Text mit den angegebenen Terminen. */
/**
 * Ein ICS-Text mit den angegebenen Terminen.
 *
 * `aboIcs` und nicht `ics`: Pest laedt ALLE Testdateien in einen Prozess, und
 * `IcsExportTest` hat ein `ics()` fuer etwas anderes. Zwei Helfer gleichen
 * Namens sind dort ein „Cannot redeclare function" beim Laden — die ganze
 * Reihe laeuft dann gar nicht erst an.
 */
function aboIcs(array $termine): string
{
    $zeilen = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Test//DE'];

    foreach ($termine as $t) {
        $zeilen[] = 'BEGIN:VEVENT';
        $zeilen[] = 'UID:'.$t['uid'];
        $zeilen[] = 'SUMMARY:'.$t['titel'];

        if ($t['ganztags'] ?? false) {
            $zeilen[] = 'DTSTART;VALUE=DATE:'.$t['start'];
            $zeilen[] = 'DTEND;VALUE=DATE:'.$t['ende'];
        } else {
            $zeilen[] = 'DTSTART:'.$t['start'];
            $zeilen[] = 'DTEND:'.$t['ende'];
        }

        $zeilen[] = 'END:VEVENT';
    }

    $zeilen[] = 'END:VCALENDAR';

    return implode("\r\n", $zeilen)."\r\n";
}

/** Was der Feed ab jetzt antwortet. */
function feedMit(array $termine): void
{
    FeedAttrappe::$inhalt = aboIcs($termine);
    FeedAttrappe::$status = 200;
}

/** Ein Termin fuer den Feed-Text. */
function fTermin(string $uid, string $titel, $start, $ende = null, bool $ganztags = false): array
{
    $start = $start instanceof \DateTimeInterface ? $start : now()->parse($start);
    $ende = $ende ?? (clone $start)->modify('+1 hour');

    return [
        'uid' => $uid,
        'titel' => $titel,
        'ganztags' => $ganztags,
        'start' => $ganztags ? $start->format('Ymd') : $start->format('Ymd\THis'),
        'ende' => $ganztags ? $ende->format('Ymd') : $ende->format('Ymd\THis'),
    ];
}

/* ------------------------------------------------------------- auswerten */

it('liest Titel, Zeitraum und Kennung aus dem Feed', function () {
    $morgen = now()->addDay()->setTime(11, 0);

    $termine = app(IcsFeed::class)->auswerten(aboIcs([fTermin('abc-123', 'Kundentermin', $morgen)]));

    expect($termine)->toHaveCount(1);
    expect($termine[0]['title'])->toBe('Kundentermin');
    expect($termine[0]['source_uid'])->toBe('abc-123');
    // Der Schluessel DIESER Zeile traegt den Tag mit — sonst ueberschreiben
    // sich die Vorkommen einer Serie gegenseitig.
    expect($termine[0]['uid'])->toBe('abc-123_'.$morgen->format('Ymd'));
    expect($termine[0]['all_day'])->toBeFalse();
});

it('zieht einem Ganztagstermin den ausschliessenden Endtag ab', function () {
    // DTEND ist bei einem Ganztagstermin AUSSCHLIESSEND: der 5. bis 6. meint
    // den 5. Ohne das ist jeder Ganztagstermin einen Tag zu lang.
    $tag = now()->addDays(3)->startOfDay();

    $termine = app(IcsFeed::class)->auswerten(aboIcs([
        fTermin('ganz-1', 'Feiertag', $tag, $tag->copy()->addDay(), ganztags: true),
    ]));

    expect($termine[0]['all_day'])->toBeTrue();
    expect($termine[0]['start_datetime']->toDateString())->toBe($tag->toDateString());
    expect($termine[0]['end_datetime']->toDateString())->toBe($tag->toDateString());
});

it('laesst weg, was weiter weg ist als das Fenster', function () {
    $termine = app(IcsFeed::class)->auswerten(aboIcs([
        fTermin('alt', 'Lange her', now()->subYears(3)),
        fTermin('fern', 'Weit weg', now()->addYears(5)),
        fTermin('nah', 'Bald', now()->addDay()),
    ]));

    expect(array_column($termine, 'title'))->toBe(['Bald']);
});

/* --------------------------------------------------------------- abgleich */

it('legt beim ersten Lauf an und beim zweiten nichts doppelt', function () {
    $a = abo();
    feedMit([fTermin('x', 'Jour Fixe', now()->addDay())]);

    expect(app(SubscriptionSync::class)->eines($a))
        ->angelegt->toBe(1)
        ->geaendert->toBe(0)
        ->weggenommen->toBe(0);

    expect(app(SubscriptionSync::class)->eines($a->fresh()))
        ->angelegt->toBe(0)
        ->geaendert->toBe(0);

    expect(SubscribedEvent::count())->toBe(1);
});

it('FUEHRT eine Zeile mit, statt sie wegzuwerfen und neu anzulegen', function () {
    /*
     * DER Fehler der alten Fassung, und der Grund, warum sie neu geschrieben
     * wurde. Sie tat stuendlich `$calendar->events()->delete()` und legte
     * danach alles neu an. Eine Erinnerung haengt ueber `subject_id` an der
     * Zeile — bei neuen Kennungen raeumt der Erinnerungs-Abgleich sie als
     * verwaist weg und legt sie neu an, mit zurueckgesetztem `delivered_at`.
     * Ergebnis waere gewesen: jede Stunde dieselbe Erinnerung erneut.
     */
    $a = abo();
    $start = now()->addDay()->setTime(9, 0);

    feedMit([fTermin('x', 'Jour Fixe', $start)]);
    app(SubscriptionSync::class)->eines($a);

    $kennungVorher = SubscribedEvent::query()->first()->getKey();

    // Derselbe Termin, nur verschoben — innerhalb desselben Tages.
    feedMit([fTermin('x', 'Jour Fixe', $start->copy()->setTime(14, 0))]);

    expect(app(SubscriptionSync::class)->eines($a->fresh()))
        ->geaendert->toBe(1)
        ->angelegt->toBe(0)
        ->weggenommen->toBe(0);

    // DIESELBE Zeile, nur mit neuer Zeit. Das ist der ganze Punkt.
    expect(SubscribedEvent::query()->first()->getKey())->toBe($kennungVorher);
    expect(SubscribedEvent::query()->first()->start_datetime->format('H:i'))->toBe('14:00');
});

it('nimmt weg, was der Feed nicht mehr nennt', function () {
    $a = abo();
    $morgen = now()->addDay();

    feedMit([
        fTermin('bleibt', 'A', $morgen),
        fTermin('faellt-weg', 'B', $morgen->copy()->addHours(3)),
    ]);
    app(SubscriptionSync::class)->eines($a);
    expect(SubscribedEvent::count())->toBe(2);

    feedMit([fTermin('bleibt', 'A', $morgen)]);

    expect(app(SubscriptionSync::class)->eines($a->fresh())['weggenommen'])->toBe(1);
    expect(SubscribedEvent::query()->pluck('source_uid')->all())->toBe(['bleibt']);
});

it('raeumt NICHTS weg, wenn der Feed gar nicht antwortet', function () {
    // Ein Kalender, der bei jeder Netzstoerung leer ist, sieht aus wie „keine
    // Termine" und nicht wie „ich konnte nicht nachsehen".
    $a = abo();
    feedMit([fTermin('x', 'Da', now()->addDay())]);
    app(SubscriptionSync::class)->eines($a);

    FeedAttrappe::$inhalt = 'kaputt';
    FeedAttrappe::$status = 500;

    $bilanz = app(SubscriptionSync::class)->eines($a->fresh());

    expect($bilanz['fehler'])->toContain('500');
    expect($bilanz['weggenommen'])->toBe(0);
    expect(SubscribedEvent::count())->toBe(1);
    expect($a->fresh()->last_error)->toContain('500');
});

it('raeumt auch dann nichts weg, wenn der Feed LEER antwortet', function () {
    // Kommt vor: ein geleerter Kalender, ein Anbieter mit Schluckauf. Den
    // ganzen Bestand daraufhin wegzuraeumen waere der teuerste moegliche
    // Schluss aus der duennsten Auskunft.
    $a = abo();
    feedMit([fTermin('x', 'Da', now()->addDay())]);
    app(SubscriptionSync::class)->eines($a);

    feedMit([]);

    expect(app(SubscriptionSync::class)->eines($a->fresh())['weggenommen'])->toBe(0);
    expect(SubscribedEvent::count())->toBe(1);
});

it('vermerkt den erfolgreichen Lauf und loescht den alten Fehler', function () {
    $a = abo(['last_error' => 'von gestern']);
    feedMit([fTermin('x', 'Da', now()->addDay())]);

    app(SubscriptionSync::class)->eines($a);

    expect($a->fresh()->last_error)->toBeNull();
    expect($a->fresh()->last_synced_at)->not->toBeNull();
});

it('laesst ein abgeschaltetes Abo beim Lauf ueber alle liegen', function () {
    abo(['is_active' => false]);
    feedMit([fTermin('x', 'Da', now()->addDay())]);

    expect(app(SubscriptionSync::class)->alle()['abos'])->toBe(0);
    expect(SubscribedEvent::count())->toBe(0);
});

it('nennt ein fehlgeschlagenes Abo mit Namen, nicht nur als Zahl', function () {
    // Ein Abo, das seit Wochen scheitert, sieht in einer Zahl wie ein
    // Rundungsfehler aus.
    abo(['name' => 'Volkers Outlook']);
    FeedAttrappe::$inhalt = 'weg';
    FeedAttrappe::$status = 503;

    $bilanz = app(SubscriptionSync::class)->alle();

    expect($bilanz['abos'])->toBe(1);
    expect(implode(' ', array_keys($bilanz['fehlgeschlagen'])))->toContain('Volkers Outlook');
});

/* ----------------------------------------------------------- Zeitzonen */

it('laesst eine SCHWEBENDE Zeit stehen, statt sie um eine Stunde zu verschieben', function () {
    /*
     * `DTSTART:20261030T140000` traegt weder `Z` noch `TZID`. Das ist im
     * Standard eine „floating time": 14 Uhr dort, wo der Leser sitzt.
     *
     * Die Manager-Fassung rief `getDateTime()` ohne Vorgabezone und liess
     * `expand()` ohne Zone laufen — sabre nimmt dann beides in UTC an, und aus
     * 14:00 wurden 15:00 (im Sommer 16:00). Gefunden beim Schreiben dieser
     * Reihe, nicht im Betrieb: Outlook schickt `TZID`, und damit stimmt die
     * Rechnung. Ein Feed ohne Zone haette still verschobene Termine geliefert.
     */
    $termine = app(IcsFeed::class)->auswerten(
        "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:schwebend\r\nSUMMARY:Schwebend\r\n"
        ."DTSTART:".now()->addDay()->setTime(14, 0)->format('Ymd\THis')."\r\n"
        ."DTEND:".now()->addDay()->setTime(15, 0)->format('Ymd\THis')."\r\n"
        ."END:VEVENT\r\nEND:VCALENDAR\r\n"
    );

    expect($termine[0]['start_datetime']->setTimezone('Europe/Berlin')->format('H:i'))->toBe('14:00');
});

it('rechnet eine Zeit MIT Zone weiter richtig um', function () {
    // Die Gegenprobe nach oben: Ohne sie waere die Korrektur oben nur eine
    // Verschiebung des Fehlers in die andere Richtung. 14:00 UTC sind im
    // Winter 15:00 in Berlin, und das soll so bleiben.
    $termine = app(IcsFeed::class)->auswerten(
        "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:mit-zone\r\nSUMMARY:Mit Zone\r\n"
        ."DTSTART:20261130T140000Z\r\nDTEND:20261130T150000Z\r\n"
        ."END:VEVENT\r\nEND:VCALENDAR\r\n"
    );

    expect($termine[0]['start_datetime']->setTimezone('Europe/Berlin')->format('H:i'))->toBe('15:00');
});
