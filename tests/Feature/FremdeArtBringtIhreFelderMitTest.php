<?php

use Carbon\CarbonImmutable;
use Peppermint\Calendar\Sources\ExternalKind;
use Peppermint\Calendar\Sources\NewExternalEvent;

/**
 * Was eine fremde Terminart verlangt, muss ankommen (#7760).
 *
 * ## Der Befund vom 14.10.2026
 *
 * Zielwaehler auf „Manager", Art „Meeting digital" — und die Maske zeigte kein
 * Feld fuer den Meeting-Link. Gemessen hat das Zielsystem sehr wohl gemeldet:
 *
 *     manager / meeting_digital    requires: meeting_url
 *
 * Die Auskunft kam an, nur benutzte sie niemand: Die Maske zeichnete nach den
 * EIGENEN Arten, und `RemoteDraft` kannte das Feld gar nicht. Wer trotzdem
 * speicherte, bekam eine Ablehnung ueber ein Feld, das er nie gesehen hatte.
 */
it('mischt die artspezifischen Felder FLACH ein', function () {
    // Das Zielsystem liest `meeting_url` auf der obersten Ebene. Lag der Beutel
    // als eigener Schluessel darin, kam dort ein Objekt an, nach dem niemand
    // fragt — das Feld fehlte, und der Termin wurde abgelehnt.
    $termin = new NewExternalEvent(
        kind: 'meeting_digital',
        title: 'Abstimmung',
        startsAt: CarbonImmutable::parse('2026-11-03 09:00'),
        endsAt: CarbonImmutable::parse('2026-11-03 10:00'),
        extra: ['meeting_url' => 'https://meet.example.com/abc'],
    );

    $gesendet = $termin->toArray();

    expect($gesendet)->toHaveKey('meeting_url')
        ->and($gesendet['meeting_url'])->toBe('https://meet.example.com/abc')
        ->and($gesendet)->not->toHaveKey('extra');
});

it('laesst den Beutel die Kernfelder nicht verdraengen', function () {
    // Sonst entschiede die Reihenfolge eines Arrays darueber, was im fremden
    // Kalender steht.
    $termin = new NewExternalEvent(
        kind: 'business',
        title: 'Der echte Titel',
        startsAt: CarbonImmutable::parse('2026-11-03 09:00'),
        endsAt: CarbonImmutable::parse('2026-11-03 10:00'),
        extra: ['title' => 'Geschmuggelt', 'kind' => 'etwas-anderes'],
    );

    $gesendet = $termin->toArray();

    expect($gesendet['title'])->toBe('Der echte Titel')
        ->and($gesendet['kind'])->toBe('business');
});

it('traegt die Verbote einer fremden Art, nicht nur ihre Pflichten', function () {
    // Ohne `forbids` boete die Maske Felder an, die drueben nicht existieren.
    // Die Eingabe verschwaende beim Speichern — und niemand erfuehre, warum die
    // Notiz weg ist.
    $art = new ExternalKind(
        key: 'meeting_digital',
        label: 'Meeting digital',
        requires: ['meeting_url'],
        forbids: ['location'],
    );

    expect($art->toArray())->toBe([
        'key' => 'meeting_digital',
        'label' => 'Meeting digital',
        'requires' => ['meeting_url'],
        'forbids' => ['location'],
    ]);
});

it('kommt ohne Angaben mit leeren Listen aus', function () {
    // Eine Art ohne Pflichten und ohne Verbote ist der Normalfall — sie darf
    // nicht durch fehlende Schluessel auffallen, sonst muss jede Lesestelle
    // zwei Faelle kennen.
    expect((new ExternalKind('business', 'Geschäftlich'))->toArray())->toBe([
        'key' => 'business',
        'label' => 'Geschäftlich',
        'requires' => [],
        'forbids' => [],
    ]);
});
