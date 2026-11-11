<?php

namespace Peppermint\Calendar\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Ein Vorgang in einem anderen System ist fehlgeschlagen.
 *
 * ## Warum das LAUT scheitern muss
 *
 * Beim Fuellen der LISTE verschluckt die Registry einen Ausfall bewusst: Ein
 * Kalender, der leer bleibt, weil ein fremdes System langsam ist, waere
 * schlimmer als einer, der unvollstaendig ist — die Unvollstaendigkeit sieht
 * man, die leere Seite kann niemand erklaeren.
 *
 * Beim SCHREIBEN gilt das Gegenteil. Zwischen „Seite geoeffnet" und „Speichern
 * gedrueckt" liegen Minuten; eine Quelle, die beim Oeffnen antwortete, kann
 * beim Anlegen tot sein. Wer das genauso verschluckt, hinterlaesst einen
 * Termin, den jemand angelegt zu haben GLAUBT und den es nirgends gibt. Eine
 * Fehlermeldung ist besser als ein Termin, der nicht existiert.
 *
 * Dieselbe Ueberlegung gilt fuers Verschieben: Das Raster darf die neue
 * Position erst behalten, wenn das andere System sie bestaetigt hat.
 *
 * ## Und auch fuer EINEN Lesevorgang: die Terminarten
 *
 * Die Grenze ist nicht Lesen gegen Schreiben, sondern Liste gegen Formular.
 * Beim Kalender ist das Schlucken richtig, bei der Artenliste eines Formulars
 * falsch — denn eine leere Artenliste laesst den Benutzer handeln, als gaebe
 * es die Art nicht. Darum wirft {@see \Peppermint\Calendar\Sources\RemoteEventSource::kinds()}
 * seit dem 11.11.2026 statt eine leere Liste zu liefern (Bug #1416): Erst damit
 * kann die Maske „nicht erreichbar" ueberhaupt sagen.
 */
final class ExternalSourceFailed extends RuntimeException
{
    public function __construct(
        string $nachricht,
        public readonly string $sourceKey,
        public readonly string $vorgang,
        ?Throwable $grund = null,
    ) {
        parent::__construct($nachricht, 0, $grund);
    }

    public static function beim(string $vorgang, string $sourceKey, ?Throwable $grund = null): self
    {
        $zusatz = $grund !== null ? ' — '.$grund->getMessage() : '';

        return new self(
            "Der Vorgang „{$vorgang}\" ist im System „{$sourceKey}\" fehlgeschlagen.{$zusatz}",
            $sourceKey,
            $vorgang,
            $grund,
        );
    }
}
