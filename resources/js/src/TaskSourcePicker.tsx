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
 */
export type TaskSource = {
    key: string;
    label: string;
    /** Die Farbe, unter der das System seine Posten fuehrt. */
    colour?: string | null;
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
                                        'flex w-full items-center gap-2 rounded-md border p-3 text-left hover:bg-muted ' +
                                        (gewaehlt ? 'border-primary' : '')
                                    }
                                >
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
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
