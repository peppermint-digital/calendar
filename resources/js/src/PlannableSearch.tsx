import { useEffect, useRef, useState } from 'react';

import { Input } from './ui/input';

/**
 * Etwas Planbares — eine Aufgabe, ein Ticket, ein Vorgang.
 *
 * Bewusst knapp: Der Kalender braucht nur, was bei der WAHL hilft. Alles
 * Weitere steht im System, aus dem es kommt — und der Weg dorthin ist
 * {@link Plannable.url}.
 */
export type Plannable = {
    id: number | string;
    title: string;
    /** Woher es kommt — Projekt, Kunde, Vorgang. */
    context?: string | null;
    /** Dringlichkeit, so wie das Quellsystem sie nennt. */
    urgency?: string | null;
    /**
     * Wann es fällig ist, schon lesbar formatiert.
     *
     * Ein fertiger Satz, kein Datum — und deshalb absichtlich NICHT `dueDate`
     * wie im Aufgabenpaket, wo es ein Datum ist. Formatiert wird im Produkt,
     * weil dieses Paket keine Sprache und kein Format hat. Wer das eine ins
     * andere schiebt, bekommt „2026-03-12" in der Auswahl stehen.
     *
     * Und nur EIN Name dafür: Zwei wären zwei Wege für dieselbe Fälligkeit, und
     * beim nächsten Umbau wird der eine gepflegt und der andere nicht.
     */
    due?: string | null;
    /**
     * Das System, aus dem der Posten kommt. Leer oder fehlend = das eigene.
     *
     * Nötig, sobald eine Liste mehrere Systeme zeigt: Kennungen sind nur
     * INNERHALB eines Systems eindeutig — „7" im Manager und „7" in der
     * Verwaltung sind zwei Aufgaben. Dieselbe Art von Grenze, die
     * `PlannableEntry.subjectType` schon zieht.
     *
     * Der Name ist der des Aufgabenpakets (`ForeignTask::$sourceKey`), damit
     * das Produkt durchreicht und nichts übersetzt.
     */
    sourceKey?: string;
    /**
     * Der Weg ins Heimatsystem.
     *
     * Er steht hier, weil `description` und `project_id` Vokabular des
     * Aufgabensystems sind und im Kalender nichts zu suchen haben. Wer mehr über
     * die Aufgabe wissen muss, als für die Wahl nötig ist, geht hin — statt dass
     * der Kalender die halbe Aufgabenmaske nachbaut.
     */
    url?: string | null;
    /**
     * Die Art der Aufgabe, in Worten — „Fehler", „Routine", „Abgabe".
     *
     * Nur die Beschriftung, nicht ihr Schlüssel: Gezeichnet wird das Wort, und
     * ein Platz, aus dem niemand liest, ist dasselbe Übel wie einer, den niemand
     * füllt (Wiki #595). `status`, `priority` und `extra` aus `ForeignTask`
     * fehlen hier aus demselben Grund.
     */
    kindLabel?: string | null;
};

/**
 * Die Antwort auf eine Suche, wenn sie mehr sagt als „hier sind die Treffer".
 *
 * Deckungsgleich mit `TaskHarvest` im Aufgabenpaket (`tasks`, `ok`, `failed`),
 * damit das Produkt die Ernte durchreicht. Und sie ist der Grund, warum
 * {@link PlannableSearchProps.search} zwei Formen zurückgeben darf: Ein nackter
 * Treffer-Array kann den Unterschied zwischen „nichts offen" und „ich konnte
 * nicht nachsehen" nicht ausdrücken, und ein Systemausfall, der als leere Liste
 * erscheint, sieht aus wie eine Auskunft (Wiki #595).
 */
export type PlannableHarvest = {
    tasks: Plannable[];
    /**
     * Die Systeme, die geantwortet haben — AUCH die mit nichts.
     *
     * Das ist die Angabe, die „wirklich nichts offen" von „niemand hat
     * geantwortet" trennt. Fehlt sie ganz, gilt die alte Lesart: eine Antwort
     * ist eine Antwort.
     */
    ok?: string[];
    /** Schlüssel => Grund, im Wortlaut der Gegenstelle. */
    failed?: Record<string, string>;
};

/**
 * Suchen und auswählen, was eingeplant werden soll.
 *
 * ## Warum das in den Kalender gehört
 *
 * „Eine bestehende Aufgabe einplanen" ist aus Sicht des Kalenders kein
 * Aufgaben-Vorgang, sondern ein {@link PlannedBlockKind}: ein Eintrag, der ein
 * `subject_type`/`subject_id` trägt. Der Kalender modelliert das ohnehin; was
 * ihm fehlte, war der Weg, das Subjekt zu finden.
 *
 * Eine NEUE Aufgabe anzulegen gehört dagegen nicht hierher — Titel, Priorität
 * und Projekt sind Begriffe des Aufgabensystems. Dafür verweist die Maske
 * hinaus, mit dem Zeitraum in der Adresse.
 *
 * ## Woher die Treffer kommen, sagt das Produkt
 *
 * `search` ist eine Funktion, kein Pfad. Jedes System fragt seine eigene
 * Stelle — das AI Brain `/api/calendar/plannable-tasks`, die Verwaltung ihre
 * offenen Tickets. Ein fester Pfad im Paket hätte alle gezwungen, denselben
 * Endpunkt zu bauen, und das Paket hätte etwas über die Datenbank des
 * Produkts gewusst.
 *
 * Gibt ein Produkt gar keine Suche mit, erscheint der ganze Weg nicht. Das ist
 * die Modularität: Ohne angestecktes Aufgabensystem gibt es nichts
 * einzuplanen, und die Maske fragt nicht danach.
 *
 * ## Nur die eigenen Aufgaben
 *
 * Hier gibt es kein Feld und keinen Parameter für eine PERSON. Die angemeldete
 * Person ist die Person; gefragt wird nach Begriff und System und sonst nichts.
 * Durchgesetzt wird es im Zielsystem — aber eine Maske, die nach fremden
 * Aufgaben suchen lässt, lädt zu einem Vorgang ein, der abgelehnt werden muss.
 *
 * ## Herkunft
 *
 * Stand bis zum 16.10.2026 im AI Brain, dort zweimal und auseinandergelaufen.
 * Der Weg hierher ist derselbe wie beim Zeitfeld und beim Datumsfeld: Wer es
 * zuerst brauchte, hat es gebaut; sobald es ein zweiter braucht, zieht es um.
 */

export interface PlannableSearchProps {
    /**
     * Liefert die Treffer zum Suchbegriff. Leerer Begriff = die ersten Vorschläge.
     *
     * Zwei Dinge sind hier absichtlich weit: Das zweite Argument ist das
     * gewählte System, und die Antwort darf statt eines Arrays eine ganze
     * {@link PlannableHarvest} sein. Beides ist additiv — eine Funktion
     * `(begriff) => Promise<Plannable[]>` bleibt zuweisbar, und die Produkte,
     * die heute genau das übergeben, ändern keine Zeile.
     */
    search: (begriff: string, source?: string) => Promise<Plannable[] | PlannableHarvest>;
    /**
     * Was mit dem gewählten Eintrag geschehen soll.
     *
     * Der zweite Parameter ist der ganze Eintrag — der Aufrufer braucht ihn,
     * sobald er mehr über die Wahl sagen will als ihre Kennung, etwa den Titel
     * im Kopf eines Assistenten. Additiv: Wer nur die Kennung nimmt, merkt
     * nichts davon.
     */
    onPick: (id: number | string, item?: Plannable) => void;
    /**
     * Das System, dessen Aufgaben gezeigt werden.
     *
     * Wird an `search` weitergegeben und entscheidet, auf WEN sich die Auskunft
     * „nichts offen" bezieht. Ohne Angabe verhält sich die Suche wie bisher:
     * eine Liste ohne Systembegriff.
     */
    source?: string;
    /**
     * Schlüssel => Name der Systeme, für die Ausfallmeldung.
     *
     * Damit dort der Name steht und nicht der Schlüssel — und zwar der LOKALE
     * Name: Ein System weiß nicht, unter welchem Kürzel es anderswo geführt
     * wird. Die Herkunft setzt der Fragende, nicht der Befragte (Wiki #595).
     */
    sourceNames?: Record<string, string>;
    placeholder?: string;
    autoFocus?: boolean;
    /** Text, wenn nichts gefunden wurde. */
    emptyText?: string;
    /** Eine Ausfallzeile. `{system}` und `{reason}` werden ersetzt. */
    unreachableText?: string;
    /** Der Weg ins Heimatsystem, als Beschriftung für Hilfsmittel. `{title}` wird ersetzt. */
    openText?: string;
}

export function PlannableSearch({
    search,
    onPick,
    source,
    sourceNames,
    placeholder = 'Titel eingeben',
    autoFocus = false,
    emptyText = 'Nichts Offenes gefunden.',
    unreachableText = 'Nicht erreichbar: {system} — {reason}',
    openText = 'Im System ansehen: {title}',
}: PlannableSearchProps) {
    const [begriff, setBegriff] = useState('');
    const [treffer, setTreffer] = useState<Plannable[]>([]);
    /**
     * Was die Antwort ueber die Systeme selbst gesagt hat — oder `null`, wenn
     * sie die alte Form hatte und darueber nichts sagt.
     */
    const [ernte, setErnte] = useState<PlannableHarvest | null>(null);
    /**
     * Ist ueberhaupt schon eine Antwort da?
     *
     * Ohne das stand waehrend der ersten Abfrage „Nichts Offenes gefunden" —
     * also ein ERGEBNIS, wo noch gar keines vorlag. Wer klickt und schnell
     * liest, haelt das fuer die Antwort und sucht den Fehler woanders. Am
     * 19.10.2026 ist genau das passiert, mir selbst, beim Durchklicken der
     * Ablaeufe (#8307).
     *
     * Seit dem 11.11.2026 ist es ausserdem die Zusicherung, auf die es im
     * Aufgaben-Weg ankommt: Solange die Ernte laeuft, steht hier WEDER eine
     * Leermeldung NOCH ein Ausfall. Beides waere eine Aussage ueber etwas, das
     * noch unterwegs ist.
     */
    const [geantwortet, setGeantwortet] = useState(false);

    /**
     * Das System, zu dem die Treffer gehoeren, die gerade dastehen.
     *
     * Wird ein anderes gewaehlt, muessen sie weg, BEVOR die neue Antwort da
     * ist: Die Aufgaben des Managers unter der Ueberschrift der Verwaltung
     * stehen zu lassen waere nicht nur falsch beschriftet, man koennte sie
     * anklicken und im falschen System einplanen.
     */
    const quelleVorher = useRef(source);

    useEffect(() => {
        let aktiv = true;

        if (quelleVorher.current !== source) {
            quelleVorher.current = source;
            setTreffer([]);
            setErnte(null);
            setGeantwortet(false);
        }

        search(begriff, source)
            .then((gefunden) => {
                if (! aktiv) {
                    return;
                }

                // Die alte Form ist ein nackter Array und sagt ueber die
                // Systeme nichts — dann gilt weiter, was sie immer hiess: eine
                // Antwort ist eine Antwort.
                if (Array.isArray(gefunden)) {
                    setTreffer(gefunden);
                    setErnte(null);
                } else {
                    setTreffer(gefunden.tasks);
                    setErnte(gefunden);
                }

                setGeantwortet(true);
            })
            .catch(() => {
                // Eine leere Liste ist hier die ehrlichere Antwort als eine
                // Fehlermeldung: Wer tippt, bekommt gleich die naechste
                // Abfrage. Und ein Wurf hier riss frueher den ganzen Dialog
                // mit, in dem die Suche steht.
                if (aktiv) {
                    setTreffer([]);
                    setErnte(null);
                    setGeantwortet(true);
                }
            });

        return () => {
            aktiv = false;
        };
    }, [begriff, search, source]);

    /** Die Systeme, die nicht nachgesehen werden konnten — Name und Grund. */
    const ausfaelle = Object.entries(ernte?.failed ?? {});

    /**
     * Heisst ein leeres Ergebnis hier wirklich „nichts offen"?
     *
     * Die drei Zustaende, die NICHT dasselbe sind (Wiki #595):
     *
     * 1. Das System hat geantwortet und nichts gemeldet — das ist eine Antwort,
     *    und sie wird hingeschrieben.
     * 2. Das System steht in `failed` — dann steht der Grund da, nicht die
     *    Leermeldung. Ein Ausfall darf nicht als leere Liste erscheinen.
     * 3. Niemand hat geantwortet, ohne dass ein Ausfall gemeldet waere. Dann
     *    wissen wir nichts, und dann steht hier nichts. Eine Leermeldung waere
     *    eine Auskunft, die keiner geprueft hat.
     *
     * Sagt die Antwort nichts ueber die Systeme (`ok` fehlt oder alte Form),
     * bleibt es bei der alten Lesart — sonst verschwaende ein Produkt, das nur
     * Treffer schickt, ploetzlich seine Leermeldung.
     */
    const wirklichNichtsOffen =
        ernte?.ok === undefined
            ? true
            : source !== undefined && source !== ''
              ? ernte.ok.includes(source)
              : ernte.ok.length > 0;

    return (
        <div className="flex flex-col gap-2" data-slot="plannable-search" data-source={source ?? ''}>
            <Input
                autoFocus={autoFocus}
                placeholder={placeholder}
                value={begriff}
                onChange={(ereignis) => setBegriff(ereignis.target.value)}
            />

            <div className="flex max-h-64 flex-col gap-1 overflow-y-auto">
                {treffer.map((eintrag) => (
                    // Der Schluessel traegt das System mit: Zwei Systeme
                    // duerfen dieselbe Kennung fuehren.
                    <div
                        key={`${eintrag.sourceKey ?? ''}:${eintrag.id}`}
                        className="flex items-stretch gap-1"
                    >
                        <button
                            type="button"
                            data-task={String(eintrag.id)}
                            data-source={eintrag.sourceKey ?? source ?? ''}
                            className="hover:border-primary/50 flex-1 rounded-md border p-2 text-left text-sm"
                            onClick={() => onPick(eintrag.id, eintrag)}
                        >
                            <div className="font-medium">{eintrag.title}</div>

                            {/* Die Zeile darunter nur, wenn es etwas zu sagen gibt —
                                sonst stuende an jedem Treffer ein leeres Kaestchen. */}
                            {(eintrag.context || eintrag.kindLabel || eintrag.urgency || eintrag.due) && (
                                <div className="text-muted-foreground mt-0.5 flex flex-wrap gap-2 text-xs">
                                    {eintrag.context && <span>{eintrag.context}</span>}
                                    {eintrag.kindLabel && <span>{eintrag.kindLabel}</span>}
                                    {eintrag.urgency && <span>{eintrag.urgency}</span>}
                                    {eintrag.due && <span>fällig {eintrag.due}</span>}
                                </div>
                            )}
                        </button>

                        {/*
                            NEBEN dem Wahlknopf und nicht darin: Ein Link in
                            einem Knopf ist verschachtelt bedienbar — der Klick
                            trifft beides, und ein Vorleser kann die zwei
                            Handlungen nicht trennen. Hier sind es zwei Flaechen
                            mit zwei Zwecken: einplanen, oder nachsehen gehen.
                        */}
                        {eintrag.url && (
                            <a
                                href={eintrag.url}
                                data-slot="plannable-link"
                                target="_blank"
                                rel="noreferrer"
                                aria-label={openText.replace('{title}', eintrag.title)}
                                className="text-muted-foreground hover:text-foreground flex items-center rounded-md border px-2 text-xs"
                            >
                                ↗
                            </a>
                        )}
                    </div>
                ))}

                {/*
                    Die Meldung erst, wenn eine Antwort da IST — und nur, wenn
                    sie wirklich eine ist. Siehe `wirklichNichtsOffen`.
                */}
                {geantwortet && treffer.length === 0 && ausfaelle.length === 0 && wirklichNichtsOffen && (
                    <p className="text-muted-foreground p-2 text-sm" data-testid="plannable-empty">
                        {emptyText}
                    </p>
                )}
            </div>

            {/*
                „Niemand ist zustaendig" und „ich konnte nicht nachsehen" sind
                zwei Aussagen (Wiki #595). Deshalb steht der Grund da, im
                Wortlaut der Gegenstelle — und die Treffer der uebrigen Systeme
                bleiben darueber stehen und waehlbar. Ein Ausfall, der die Liste
                leer macht, sieht aus wie eine Auskunft.
            */}
            {ausfaelle.length > 0 && (
                <div role="status" data-slot="plannable-failed" className="flex flex-col gap-1">
                    {ausfaelle.map(([schluessel, grund]) => (
                        <p
                            key={schluessel}
                            data-source={schluessel}
                            className="text-muted-foreground text-xs"
                        >
                            {unreachableText
                                .replace('{system}', sourceNames?.[schluessel] ?? schluessel)
                                .replace('{reason}', grund)}
                        </p>
                    ))}
                </div>
            )}
        </div>
    );
}

export default PlannableSearch;
