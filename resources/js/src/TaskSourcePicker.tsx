/**
 * Ein waehlbares Aufgabensystem.
 *
 * Deckungsgleich mit den drei Fragen, die eine `TaskSource` im Aufgabenpaket
 * beantwortet — `key()`, `label()`, `colour()`. Das ist Absicht: Das Produkt
 * reicht seine Registry durch, ohne zu uebersetzen. Eine Uebersetzung dazwischen
 * waere die Stelle, an der beim naechsten Feld eine Angabe liegen bleibt.
 *
 * Mehr steht hier nicht, insbesondere keine Aufgaben: Die kommen erst, wenn das
 * System gewaehlt IST — das ist der ganze Grund fuer diese Stufe.
 *
 * Die Ausnahme ist `notPlannableReason`: Das ist keine vierte Frage an die
 * Registry, sondern eine Angabe des Aufrufers UEBER dieses System — was er mit
 * einer Aufgabe von dort anfangen kann. Deshalb steht sie hier und nicht
 * drueben.
 */
export type TaskSource = {
    key: string;
    label: string;
    /** Die Farbe, unter der das System seine Posten fuehrt. */
    colour?: string | null;
    /**
     * Warum aus diesem System gerade nichts eingeplant werden kann. Fehlt oder
     * `null` = es geht.
     *
     * Der Satz steht als zweite, gedaempfte Zeile unter dem Namen. Die Zeile
     * bleibt dabei anklickbar und ohne `disabled`: Suchen geht weiterhin, und
     * das ist der Wert der Foederation — ihn wegzunehmen waere schlimmer als die
     * Absage am Ende.
     *
     * ## Warum das ein EIGENES Feld ist und nicht in `label` gehoert
     *
     * „Verwaltung (nicht einplanbar)" im `label` waere die naheliegende
     * Loesung und ist nachweisbar falsch: {@link EventComposer} baut aus
     * DENSELBEN Eintraegen die Namenstabelle fuer die Ausfallzeile der Suche
     * (`sourceNames`, aus `system.label`), und {@link PlannableSearch} liest
     * daraus `sourceNames?.[schluessel] ?? schluessel`. Die Ausfallzeile hiesse
     * dann „Verwaltung (nicht einplanbar) konnte nicht gefragt werden".
     *
     * Ein Feld zweckentfremden heisst, es an seiner zweiten Lesestelle
     * kaputtzumachen — und die zweite Lesestelle sieht man beim Bauen nicht.
     * Deshalb bleiben die beiden Felder getrennt, auch wenn sie einmal
     * nebeneinander wie dasselbe aussehen.
     *
     * Und es heisst nicht `unavailableReason`: Das System IST verfuegbar, es
     * laesst sich durchsuchen und seine Aufgaben erscheinen. Nur einplanen geht
     * nicht.
     *
     * Es ist ein Wert, den der Aufrufer mitgibt — keine Abfrage. Der Satz steht
     * beim Zeichnen schon da, wie die Liste selbst (siehe unten).
     */
    notPlannableReason?: string | null;
};

export type TaskSourcePickerLabels = {
    /** Ueber der Liste. */
    heading: string;
    /** Wenn kein System angesteckt ist. */
    empty: string;
};

export const defaultTaskSourcePickerLabels: TaskSourcePickerLabels = {
    heading: 'Aus welchem System?',
    empty: 'Hier ist kein Aufgabensystem angesteckt.',
};

export type TaskSourcePickerProps = {
    /** Die Systeme, in der Reihenfolge, in der sie stehen sollen. Hier wird NICHT sortiert. */
    options: TaskSource[];
    /** Der Schluessel des gewaehlten Systems. */
    value?: string;
    onPick: (source: TaskSource) => void;
    labels?: Partial<TaskSourcePickerLabels>;
    className?: string;
};

/**
 * Die Wahl des Aufgabensystems — die Stufe VOR den Aufgaben.
 *
 * ## Warum hier zuerst das System kommt und bei den Terminarten nicht
 *
 * Das sieht wie ein Widerspruch zum {@link KindPicker} aus und ist keiner. Dort
 * steht das System klein an der Zeile, weil die Liste kurz ist: zehn anlegbare
 * Terminarten ueber alle vier Systeme. Hier waeren es hunderte. Bastian am
 * 11.11.2026, woertlich:
 *
 * > „bei der Aufgabenart muss ich das System ja aber vorher waehlen, oder? Weil
 * > sonst kriege ich ja einfach eine unendlich lange Liste mit hunderten
 * > Aufgaben. […] Bei Aufgaben ist es wahrscheinlich besser, wenn wir
 * > Aufgabesystem und dann Aufgabe machen."
 *
 * Die Regel dahinter ist dieselbe wie dort, nur anders herum angewandt: Eine
 * flache Liste traegt, solange man sie ueberblickt. Sobald nicht, ist die
 * Gliederung keine Bequemlichkeit mehr, sondern das Einzige, was die Wahl
 * ueberhaupt treffbar macht.
 *
 * ## Warum es hier keinen Lade- und keinen Ausfallzustand gibt
 *
 * Weil die Systemliste NICHT ueber das Netz kommt. Sie ist lokale Angabe — im
 * Aufgabenpaket `TaskSourceRegistry::available()`, das niemanden fragt, und im
 * Produkt steht sie damit schon beim Zeichnen der Seite da. Daraus folgt beides:
 *
 * - Die Zahl der Systeme kann nicht nachtraeglich wachsen. Eine Stufe, die bei
 *   einem einzigen System entfaellt, kann deshalb nicht nachtraeglich
 *   aufspringen — anders als bei den Terminarten, wo genau das drohte.
 * - Ein System kann hier nicht „nicht antworten". Es antwortet erst beim Ernten
 *   seiner Aufgaben, und ein Ausfall dabei gehoert an die Stufe danach
 *   ({@link PlannableSearch}), wo er auch entsteht.
 *
 * Wer die Liste eines Tages ueber das Netz holt, nimmt damit beide Zusicherungen
 * weg und muss sie hier nachbauen. Deshalb steht die Voraussetzung hier und
 * nicht nur im Auftrag.
 *
 * ## Was hier NICHT liegt
 *
 * Woher die Systeme kommen und welche Aufgaben sie fuehren. Das entscheidet der
 * Aufrufer ({@link EventComposer}) — diese Komponente zeichnet eine Liste, die
 * sie bekommt.
 */
export function TaskSourcePicker({
    options,
    value,
    onPick,
    labels,
    className = '',
}: TaskSourcePickerProps) {
    const beschriftung = { ...defaultTaskSourcePickerLabels, ...labels };

    return (
        <div data-slot="task-source-picker" className={`space-y-3 ${className}`.trim()}>
            <p className="text-sm font-medium">{beschriftung.heading}</p>

            {options.length === 0 && (
                <p className="text-sm text-muted-foreground">{beschriftung.empty}</p>
            )}

            {options.length > 0 && (
                <ul className="flex flex-col gap-2">
                    {options.map((system) => {
                        const gewaehlt = value === system.key;

                        return (
                            <li key={system.key}>
                                <button
                                    type="button"
                                    data-source={system.key}
                                    onClick={() => onPick(system)}
                                    aria-pressed={gewaehlt}
                                    className={
                                        'w-full rounded-md border p-3 text-left hover:bg-muted ' +
                                        (gewaehlt ? 'border-primary' : '')
                                    }
                                >
                                    <span className="flex items-center gap-2">
                                        {/* Die Farbe ist das Wiedererkennungszeichen des
                                            Systems aus dem Raster — und nur das. Vor
                                            Hilfsmitteln verborgen: Vorgelesen waere sie
                                            ein Punkt ohne Aussage, und die Beschriftung
                                            daneben sagt dasselbe in Worten. */}
                                        {system.colour && (
                                            <span
                                                aria-hidden="true"
                                                className="size-2.5 shrink-0 rounded-full"
                                                style={{ backgroundColor: system.colour }}
                                            />
                                        )}
                                        <span className="text-sm font-medium">{system.label}</span>
                                    </span>

                                    {/*
                                        Warum aus diesem System gerade nichts
                                        eingeplant werden kann — als zweite Zeile
                                        unter dem Namen und NICHT als Zusatz im
                                        Namen selbst (siehe {@link TaskSource}).

                                        Kein Tooltip: Was nur beim Daraufzeigen
                                        erscheint, gibt es auf dem Handy nicht.
                                        Und kein `disabled`/`aria-disabled`: Die
                                        Zeile bleibt waehlbar, weil Suchen
                                        weiterhin geht.

                                        Innerhalb des Knopfes, damit Hilfsmittel
                                        den Satz mit vorlesen — eine Absage, die
                                        nur zu sehen ist, waere fuer sie wieder
                                        der Weg ueber vier Stufen ins Leere.
                                    */}
                                    {system.notPlannableReason && (
                                        <span
                                            data-slot="task-source-not-plannable"
                                            className="mt-1 block text-xs text-muted-foreground"
                                        >
                                            {system.notPlannableReason}
                                        </span>
                                    )}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
