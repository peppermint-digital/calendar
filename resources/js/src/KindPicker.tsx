import type { EventKind } from './types';

/**
 * Eine waehlbare Terminart — samt dem System, in dem sie entsteht.
 *
 * Der Systemschluessel ist leer fuer die Arten DIESES Systems. Das ist nicht
 * nur eine Abkuerzung: Er wird so weitergegeben, wie `useRemoteTarget` ihn
 * erwartet, wo leer „der eigene Kalender" heisst.
 */
export type KindOption = {
    /** Das Zielsystem. Leer = der eigene Kalender. */
    source: string;
    /**
     * Der Systemname, klein an der Zeile.
     *
     * Er kommt aus der LOKALEN Quellen-Beschriftung (`RemoteSource.label`), die
     * das Produkt hereingibt — nie aus der Antwort des fremden Systems. Die
     * Herkunft setzt der Fragende, nicht der Befragte: Ein System weiss nicht,
     * unter welchem Kuerzel es anderswo gefuehrt wird, und duerfte es sich
     * selbst eines geben, stuenden in vier Oberflaechen vier verschiedene
     * Woerter fuer dasselbe System (Wiki #595).
     *
     * Leer heisst: nichts hinschreiben. Siehe {@link KindPicker}.
     */
    system: string;
    kind: EventKind;
};

export type KindPickerLabels = {
    /** Ueber der Liste. */
    heading: string;
    /** Solange die Arten der fremden Systeme noch unterwegs sind. */
    loading: string;
    /** Welche Systeme nicht geantwortet haben. `{systems}` wird ersetzt. */
    unreachable: string;
    /** Welche Systeme den Zugriff ABGELEHNT haben. `{systems}` wird ersetzt. */
    notAllowed: string;
    /** Wenn es nichts zu waehlen gibt. */
    empty: string;
};

export const defaultKindPickerLabels: KindPickerLabels = {
    heading: 'Was für ein Termin?',
    loading: 'Weitere Terminarten werden geladen …',
    unreachable:
        'Nicht erreichbar: {systems}. Die Terminarten dieser Systeme fehlen in der Liste — ' +
        'die übrigen sind vollständig.',
    /*
     * Der Satz, um den es in Aufgabe #9882 geht. Drei Dinge daran sind
     * Absicht:
     *
     * 1. „Nicht freigegeben" und nicht „nicht erreichbar" — das System hat
     *    geantwortet. Bastian las am 12.11.2026 den falschen Satz.
     * 2. Kein „Versuche es später noch einmal". Wiederholen hilft bei einer
     *    Absage nicht, und ein leeres Versprechen kostet eine zweite
     *    Wartezeit und sagt danach dasselbe.
     * 3. Derselbe zweite Halbsatz wie oben („die übrigen sind vollständig").
     *    Er ist die eigentliche Auskunft: Was fehlt, ist benannt, und was
     *    dasteht, ist verlässlich.
     */
    notAllowed:
        'Nicht freigegeben: {systems}. Die Terminarten dieser Systeme fehlen in der Liste — ' +
        'die übrigen sind vollständig.',
    empty: 'Hier lässt sich gerade keine Terminart anlegen.',
};

export type KindPickerProps = {
    /**
     * Alle waehlbaren Arten, in der Reihenfolge, in der sie stehen sollen.
     *
     * Die Reihenfolge entscheidet der Aufrufer und zwar vollstaendig: Hier wird
     * NICHT sortiert. Nach System zu sortieren zerlegte die Liste wieder in die
     * Produktkaestchen, die sie gerade aufloest (Wiki #595).
     */
    options: KindOption[];
    /** Die getroffene Wahl — Systemschluessel und Art. */
    value?: { source: string; kind: string };
    onPick: (option: KindOption) => void;
    /**
     * Systeme, die nicht geantwortet haben — mit ihrem Namen, nicht ihrem
     * Schluessel.
     */
    unreachable?: string[];
    /**
     * Systeme, die GEANTWORTET und den Zugriff abgelehnt haben — mit ihrem
     * Namen, nicht ihrem Schluessel.
     *
     * Eigener Prop und nicht Teil von {@link unreachable}, weil es eine
     * eigene Aussage ist. Dieselbe Begruendung, aus der `writable` und
     * `unreachable` am Zieleintrag getrennt sind.
     */
    notAllowed?: string[];
    /** Laufen noch Abfragen? */
    loading?: boolean;
    labels?: Partial<KindPickerLabels>;
    className?: string;
};

/**
 * Die Wahl der Terminart — alle Arten aller Systeme in EINER Liste.
 *
 * ## Warum eine Liste und keine zwei Stufen
 *
 * Die Maske fragte bis zum 11.11.2026 zuerst nach dem System („Anlegen in",
 * eine Klappliste) und bot danach die Arten dieses Systems an. Das verlangt
 * eine Entscheidung, die niemand treffen kann: Wer einen Geschaeftstermin
 * anlegen will, weiss, dass es ein Geschaeftstermin ist — in welchem der vier
 * Systeme diese Art gefuehrt wird, ist eine Eigenschaft der Art und keine
 * Frage an den Menschen.
 *
 * Also umgekehrt: **Man waehlt die Art. Das System ergibt sich daraus** und
 * steht nur klein daneben, damit sichtbar bleibt, wohin der Termin geht.
 *
 * ## Warum das traegt
 *
 * Weil die Liste kurz ist. Gemessen am 11.11.2026 ueber alle vier Systeme:
 * zehn anlegbare Arten (Manager 6, AI Brain 2, CRM 1, Verwaltung 1). Alles
 * andere sind Zeitbloecke und Spiegelungen, die ohnehin aus einer anderen
 * Handlung entstehen und deshalb gar nicht in der Auswahl stehen. Wuerde diese
 * Zahl auf das Dreifache wachsen, waere die flache Liste die falsche Form —
 * dann aber bitte mit einer Suche und nicht mit Systemkaesten.
 *
 * ## Warum bei eigenen Arten KEIN Systemname steht
 *
 * Der Name beantwortet „wo entsteht der Termin?". Fuer die eigenen Arten ist
 * die Antwort „hier", und das ist die Vorgabe — sie braucht kein Schild. Dazu
 * kommt ein handfester Grund: Das Paket kennt den Namen des eigenen Systems
 * nicht. Eine Beschriftung tragen nur die fremden Quellen
 * (`RemoteSource.label`); den eigenen Namen muesste jedes Produkt neu
 * hereingeben, nur um das Selbstverstaendliche hinzuschreiben.
 *
 * So steht die Beschriftung genau an den Zeilen, an denen sie etwas aendert:
 * Die legen drueben an.
 *
 * ## Was hier NICHT liegt
 *
 * Woher die Arten kommen und welche anlegbar sind. Das entscheidet der
 * Aufrufer ({@link EventComposer}) — diese Komponente zeichnet eine Liste, die
 * sie bekommt.
 */
export function KindPicker({
    options,
    value,
    onPick,
    unreachable = [],
    notAllowed = [],
    loading = false,
    labels,
    className = '',
}: KindPickerProps) {
    const beschriftung = { ...defaultKindPickerLabels, ...labels };

    return (
        <div data-slot="kind-picker" className={`space-y-3 ${className}`.trim()}>
            <p className="text-sm font-medium">{beschriftung.heading}</p>

            {options.length === 0 && ! loading && (
                <p className="text-sm text-muted-foreground">{beschriftung.empty}</p>
            )}

            {options.length > 0 && (
                <ul className="flex flex-col gap-2">
                    {options.map((eintrag) => {
                        const gewaehlt =
                            value?.kind === eintrag.kind.key && value?.source === eintrag.source;

                        return (
                            // Der Schluessel traegt BEIDES: Zwei Systeme duerfen
                            // denselben Artenschluessel fuehren — `business` im
                            // Manager und `business` drueben sind zwei Zeilen.
                            <li key={`${eintrag.source}:${eintrag.kind.key}`}>
                                <button
                                    type="button"
                                    data-kind={eintrag.kind.key}
                                    data-source={eintrag.source}
                                    onClick={() => onPick(eintrag)}
                                    aria-pressed={gewaehlt}
                                    className={
                                        'w-full rounded-md border p-3 text-left hover:bg-muted ' +
                                        (gewaehlt ? 'border-primary' : '')
                                    }
                                >
                                    <span className="text-sm font-medium">{eintrag.kind.label}</span>
                                    {eintrag.system !== '' && (
                                        // Klein und daneben, nicht darueber: Das
                                        // System ist Beschriftung, nicht
                                        // Gliederung.
                                        <span className="ml-2 text-xs text-muted-foreground">
                                            ({eintrag.system})
                                        </span>
                                    )}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}

            {loading && <p className="text-xs text-muted-foreground">{beschriftung.loading}</p>}

            {/*
                „Niemand ist zustaendig" und „ich konnte nicht nachsehen" sind
                zwei Aussagen (Wiki #595). Ein Ziel, das nicht antwortet, ist
                deshalb ein sichtbarer Hinweis MIT Namen — und die uebrigen
                Arten bleiben waehlbar. Ein Ausfall, der als leere Liste
                erscheint, sieht aus wie eine Auskunft.
            */}
            {unreachable.length > 0 && (
                <p role="status" data-slot="kind-picker-unreachable" className="text-xs text-muted-foreground">
                    {beschriftung.unreachable.replace('{systems}', unreachable.join(', '))}
                </p>
            )}

            {/*
                Und die Absage als EIGENE Zeile, nicht als zweiter Eintrag in
                der Liste oben. „Hat nicht geantwortet" und „hat abgelehnt"
                sind zwei Aussagen mit zwei verschiedenen naechsten Schritten
                — eine Zeile fuer beide sagt „irgendwas war" und damit nichts.
                Dasselbe Argument, mit dem `PlannableSearch` seine vier
                Gruende auf vier Saetze abbildet statt auf einen.
            */}
            {notAllowed.length > 0 && (
                <p role="status" data-slot="kind-picker-not-allowed" className="text-xs text-muted-foreground">
                    {beschriftung.notAllowed.replace('{systems}', notAllowed.join(', '))}
                </p>
            )}
        </div>
    );
}
