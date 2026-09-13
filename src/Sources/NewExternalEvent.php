<?php

namespace Peppermint\Calendar\Sources;

use Carbon\CarbonImmutable;

/**
 * Ein Termin, der in einem ANDEREN System entstehen soll.
 *
 * Dieselben Felder wie beim Lesen, plus die Art des Zielsystems — denn dort
 * entscheidet sie, welche Felder ueberhaupt erlaubt sind.
 *
 * Kein Modell, keine Zeile hier: Der Termin gehoert ab dem ersten Moment dem
 * anderen System. Eine Kopie waere eine zweite Wahrheit, und die Frage, welche
 * gewinnt, haette keine gute Antwort — dieselbe Ueberlegung wie beim Lesen.
 */
final class NewExternalEvent
{
    /**
     * @param  array<string, mixed>  $extra  Felder, die nur das Zielsystem
     *                                       kennt (Projekt, Kunde, Abrechnung).
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly bool $allDay = false,
        public readonly ?string $location = null,
        public readonly ?string $description = null,
        public readonly array $extra = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'kind' => $this->kind,
            'title' => $this->title,
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'all_day' => $this->allDay,
            'location' => $this->location,
            'description' => $this->description,
            'extra' => $this->extra,
        ], static fn ($value) => $value !== null && $value !== []);
    }
}
