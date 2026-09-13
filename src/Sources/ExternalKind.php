<?php

namespace Peppermint\Calendar\Sources;

/**
 * Eine Terminart, wie ein ANDERES System sie anbietet.
 *
 * Arten sind produkteigen: Was der eine „Geschaeftlich" nennt, kennt der
 * andere gar nicht. Wer drueben einen Termin anlegen will, muss die Art
 * benennen koennen — also muss das fremde System sagen, welche es hat, statt
 * dass hier jemand raet.
 *
 * Bewusst kein `EventKind`: Das ist die Art einer Anwendung, mit Profil,
 * Loeschverhalten und Regeln. Hier kommt nur an, was zur Auswahl noetig ist.
 */
final class ExternalKind
{
    /**
     * @param  array<int, string>  $requires  Felder, ohne die das andere System
     *                                        ablehnt — damit eine Maske sie
     *                                        verlangen kann, statt den Fehler
     *                                        erst nach dem Absenden zu zeigen.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $requires = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'requires' => $this->requires,
        ];
    }
}
