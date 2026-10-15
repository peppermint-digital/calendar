import type { ReactNode } from 'react';

/**
 * Der Rahmen, in dem eine Kalenderseite sitzt: Kopf, Ansichtsleiste, Raster —
 * und daneben, wenn das Produkt eine hat, eine Spalte zum Einplanen.
 *
 * ## Warum die Anordnung ins Paket gehoert
 *
 * Sie ist der Teil, den jedes Produkt identisch schreiben wuerde: Kopf oben,
 * Raster darunter auf volle Hoehe, Seite scrollt nicht, das Zeitraster tut es.
 * Viermal geschrieben driftet sie viermal.
 *
 * Und genau das war passiert. Am 15.10.2026 gemessen, vier Systeme, vier
 * Anordnungen:
 *
 * | System     | Rahmen                  | Vollhoehe     | Spalte rechts |
 * | ---------- | ----------------------- | ------------- | ------------- |
 * | Manager    | eine Karte              | `lg:flex-1`   | nein          |
 * | CRM        | sechs Karten            | `xl:flex-1`   | ja            |
 * | Verwaltung | acht Karten             | `xl:flex-1`   | ja            |
 * | Brain      | gar keine Karte         | keine         | nein          |
 *
 * Dabei zogen alle vier ihr Raster und ihre Leiste schon aus diesem Paket. Die
 * Bausteine waren also laengst geteilt — nur die Anordnung nicht, und sichtbar
 * ist genau sie. Wer vier Kalender nebeneinanderlegt, sieht nicht die
 * Komponenten, sondern wo sie stehen und wie hoch sie sind.
 *
 * Vorlage ist der Manager: Er ist das meistbenutzte System, und seine Fassung
 * ist die einzige, bei der das Raster die Fensterhoehe wirklich ausnutzt.
 *
 * ## Wer scrollt, und wer nicht
 *
 * Ab `lg` ist der Rahmen so hoch wie das Fenster und schneidet ab. Das Raster
 * bekommt die Resthoehe und scrollt innen — eine Woche von 7 bis 23 Uhr passt
 * nie auf einen Schirm, aber der Kopf mit „Termin erstellen" soll stehen
 * bleiben.
 *
 * Darunter (Telefon, kleines Tablet) gilt das NICHT: dort umbricht die
 * Filterleiste so stark, dass fuer das Raster keine brauchbare Hoehe
 * uebrigbleibt. Die Seite scrollt dann als Ganzes.
 *
 * ## Die Spalte rechts ist ein Slot, keine Entscheidung
 *
 * CRM und Verwaltung stellen neben das Raster eine Liste mit dem, was noch
 * einzuplanen ist, und ziehen daraus ins Raster. Manager und Brain haben das
 * nicht. Beides ist richtig — es haengt daran, ob im System ueberhaupt
 * Planbares entsteht.
 *
 * Deshalb entscheidet das nicht der Rahmen, sondern ob ein Produkt `aside`
 * fuellt. Genau so haelt es {@link MailboxBrowser} mit seinem Beiwagen.
 *
 * Zweispaltig erst ab `xl`, und das ist gemessen, nicht geraten: Untereinander
 * sind Liste und Raster auf einem gewoehnlichen Bildschirm nie gleichzeitig zu
 * sehen, und „ins Raster ziehen" verlangt dann Scrollen mitten im Ziehen.
 * Daran war die erste Fassung in der Verwaltung gescheitert.
 *
 * ## Klassen als Text, nicht als Variable
 *
 * `heightClass` und `asideWidth` nehmen fertige Tailwind-Klassen. Sie werden
 * NICHT zusammengesetzt (kein `xl:w-${n}`): Tailwind liest Quelltext und sieht
 * keine Variablen — eine zusammengebaute Klasse erzeugt lautlos kein CSS.
 *
 * Wer einen eigenen Wert uebergibt, muss ihn ausserdem als Text in der EIGENEN
 * Datei stehen haben, denn Tailwind scannt das Projekt des Produkts. Die
 * Vorgabewerte hier stehen in dieser Datei und werden darum mitgebaut —
 * vorausgesetzt, das Produkt hat
 * `@source '../../node_modules/@peppermint-digital/calendar/dist'` in seiner
 * `app.css`. Fehlt die Zeile, fehlen genau die Klassen, die nur das Paket
 * benutzt; am 15.10.2026 war das in drei von vier Systemen so.
 */

export interface CalendarBrowserProps {
    /**
     * Die Ueberschrift links im Kopf — Titel und Beschreibung.
     *
     * Ab `md` sichtbar. Auf dem Telefon bleibt sie weg: Dort steht der
     * Zeitraum schon in der Ansichtsleiste, und die Hoehe wird gebraucht.
     */
    title?: ReactNode;
    /** Die Bedienelemente rechts im Kopf: Filter, Schalter, „Termin erstellen". */
    actions?: ReactNode;
    /**
     * Ein Streifen unter dem Kopf, ueber die ganze Breite — Hinweis auf
     * Ueberschneidungen, auf einen fremden Kalender, auf eine stumme Quelle.
     */
    notice?: ReactNode;
    /** Die Ansichtsleiste: Monat/Woche/Tag, vor und zurueck, Heute. Sitzt in der Karte, ueber dem Raster. */
    toolbar?: ReactNode;
    /** Das Zeitraster. Bekommt die Resthoehe. */
    grid: ReactNode;
    /** Die Spalte rechts neben dem Raster. Fehlt sie, nimmt das Raster die ganze Breite. */
    aside?: ReactNode;
    /** Breite der rechten Spalte als fertige Tailwind-Klasse. */
    asideWidth?: string;
    /**
     * Ab `lg` so hoch wie das Fenster, statt mit dem Inhalt zu wachsen.
     *
     * Aus nur dort, wo die Seite als Ganzes scrollen soll — eine
     * Listenansicht etwa, die keine feste Hoehe braucht.
     */
    fullHeight?: boolean;
    /**
     * Welche Hoehe „volles Fenster" heisst, als fertige Tailwind-Klasse.
     *
     * Die Vorgabe laesst `6rem` fuer die Kopfzeile der Anwendung. Hat ein
     * System eine hoehere, uebergibt es seinen eigenen Wert — als Text in der
     * eigenen Datei.
     */
    heightClass?: string;
    /** Zusaetzliche Klassen fuer den aeusseren Rahmen. */
    className?: string;
}

/** Leere und falsche Werte raus, damit kein `class="a  undefined"` entsteht. */
function klassen(...teile: Array<string | false | null | undefined>): string {
    return teile.filter(Boolean).join(' ');
}

export function CalendarBrowser({
    title,
    actions,
    notice,
    toolbar,
    grid,
    aside,
    asideWidth = 'xl:w-80',
    fullHeight = true,
    heightClass = 'lg:h-[calc(100dvh-6rem)] lg:overflow-hidden',
    className,
}: CalendarBrowserProps) {
    // `aside` ist ein ReactNode: `0` ist ein gueltiger Knoten und zugleich
    // unwahr. Ohne diese Zeile gaebe `aside && 'xl:flex-row'` die Zahl 0
    // zurueck — und die landete als Klasse im Markup.
    const hatBeiwagen = Boolean(aside);

    return (
        <div
            data-slot="calendar-browser"
            className={klassen(
                'flex flex-col space-y-4 p-4 md:space-y-6 md:p-6',
                fullHeight && heightClass,
                className,
            )}
        >
            {(title || actions) && (
                <div
                    data-slot="calendar-head"
                    className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between md:gap-3"
                >
                    {title ? <div className="hidden md:block">{title}</div> : null}
                    {actions ? (
                        <div className="flex flex-col gap-2 md:flex-row md:flex-wrap md:items-center">{actions}</div>
                    ) : null}
                </div>
            )}

            {notice}

            <div
                data-slot="calendar-body"
                className={klassen(
                    'flex min-h-0 flex-col gap-4',
                    fullHeight && 'lg:flex-1',
                    hatBeiwagen && 'xl:flex-row xl:items-stretch',
                )}
            >
                {/* Die Klassen der Karte stehen hier als Text statt als
                    `<Card>`: Das Paket bringt bewusst keine Bedienelemente mit,
                    und eine eigene Card waere die zweite Antwort auf die Frage,
                    wie eine Karte aussieht. Am 15.10.2026 war `card.tsx` in
                    allen vier Systemen byteweise dieselbe Datei — diese Zeile
                    ist ihr Inhalt.

                    Auf dem Telefon fallen Rand, Grund und Schatten weg: Eine
                    Karte, die so breit ist wie der Schirm, ist keine Karte
                    mehr, sondern nur ein Strich aussen herum. */}
                <div
                    data-slot="calendar-card"
                    className={klassen(
                        'flex flex-col rounded-xl border bg-card text-card-foreground shadow-sm',
                        'max-md:border-0 max-md:bg-transparent max-md:shadow-none',
                        fullHeight && 'lg:min-h-0 lg:flex-1 lg:overflow-hidden',
                        hatBeiwagen && 'xl:min-w-0 xl:flex-1',
                    )}
                >
                    <div
                        className={klassen(
                            'flex flex-col gap-3 p-4 max-md:p-0 md:p-6',
                            fullHeight && 'lg:min-h-0 lg:flex-1',
                        )}
                    >
                        {toolbar}
                        <div className={klassen('flex flex-col', fullHeight && 'lg:min-h-0 lg:flex-1')}>{grid}</div>
                    </div>
                </div>

                {aside ? (
                    <div
                        data-slot="calendar-aside"
                        className={klassen('shrink-0', asideWidth, fullHeight && 'xl:min-h-0 xl:overflow-y-auto')}
                    >
                        {aside}
                    </div>
                ) : null}
            </div>
        </div>
    );
}

export default CalendarBrowser;
