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
 * Warum ein System nicht geantwortet hat — als geschlossene Menge.
 *
 * Dieselben vier Namen wie `SourceFailure::REASONS` im Aufgabenpaket, und
 * dieselbe Absicht: Es sind vier verschiedene Lagen mit vier verschiedenen
 * nächsten Schritten. Ein einziger Satz wirft sie zusammen, und dann sagt die
 * Oberfläche „etwas ist schiefgelaufen" — womit niemand etwas anfangen kann.
 *
 * | Grund | was es heißt |
 * |---|---|
 * | `not_offered` | kein Fehler — das System bietet Aufgaben nicht an |
 * | `not_allowed` | Wiederholen hilft nicht |
 * | `unreachable` | Wiederholen hilft vielleicht |
 * | `tool_failed` | gehört gemeldet |
 *
 * **Kein „unknown":** Das wäre ein fünfter Grund, der wie ein Grund aussieht
 * und keiner ist — er landet in der Tabelle, die Gründe auf Sätze abbildet,
 * bekommt dort einen Satz, und der Satz sagt nichts. `null` zwingt die Maske
 * stattdessen auf den {@link PlannableFailure.message}, der tatsächlich etwas
 * sagt. Die Begründung steht genauso am PHP-Feld.
 */
export type PlannableFailureReason = 'not_offered' | 'not_allowed' | 'unreachable' | 'tool_failed';

/**
 * Ein System konnte nicht gefragt werden — Grund und Wortlaut getrennt.
 *
 * Die Namen sind die von `SourceFailure::toArray()`, bis auf den Unterstrich in
 * `may_retry`: Das Produkt reicht die Ernte durch und übersetzt nichts. Wer hier
 * `mayRetry` schriebe, hätte eine Übersetzungsschicht, die beim nächsten Umbau
 * auseinanderläuft — dieselbe Begründung wie bei {@link Plannable.sourceKey}.
 */
export type PlannableFailure = {
    /** Das System selbst — derselbe Schlüssel, unter dem der Ausfall steht. */
    source: string;
    /** Einer der vier Namen, oder `null`, wenn niemand einen genannt hat. */
    reason: PlannableFailureReason | null;
    /** Der Satz, den ein Mensch liest — im Wortlaut der Gegenstelle. */
    message: string;
    /**
     * Hilft Warten?
     *
     * Wird DURCHGEREICHT und nicht nachgerechnet: Die Regel gehört dem
     * Zielsystem (`SourceFailure::mightPassByItself()`). Eine Maske, die sie aus
     * dem Grund nachbaut, muss ein zweites Mal korrigiert werden, sobald ein
     * fünfter Grund dazukommt.
     */
    may_retry: boolean;
};

/**
 * Die Antwort auf eine Suche, wenn sie mehr sagt als „hier sind die Treffer".
 *
 * Deckungsgleich mit `TaskHarvest` im Aufgabenpaket (`tasks`, `ok`, `failures`,
 * `complete`), damit das Produkt die Ernte durchreicht. Und sie ist der Grund,
 * warum {@link PlannableSearchProps.search} zwei Formen zurückgeben darf: Ein
 * nackter Treffer-Array kann den Unterschied zwischen „nichts offen" und „ich
 * konnte nicht nachsehen" nicht ausdrücken, und ein Systemausfall, der als leere
 * Liste erscheint, sieht aus wie eine Auskunft (Wiki #595).
 *
 * ## `failed` gibt es nicht mehr
 *
 * Bis zum 11.11.2026 hieß das Feld `failed` und trug `Schlüssel => Satz`. Jetzt
 * heißt es {@link PlannableHarvest.failures} und trägt ein OBJEKT. Der alte
 * Name ist ENTFERNT und nicht als veraltet behalten: Wer ihn weiter liest,
 * bekommt einen Compilerfehler statt eines stummen „[object Object]" in seiner
 * Maske. Die Form war seit v0.95.0 veröffentlicht — dann muss der Bruch laut
 * sein, und zwar in dem Moment, in dem jemand hinsieht.
 *
 * Die ALTE Array-Form (`Plannable[]`) ist davon nicht betroffen und bleibt: Sie
 * sagte über die Systeme ohnehin nichts, und `failed` lebte nur hier.
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
    /** Schlüssel => warum dieses System still blieb. */
    failures?: Record<string, PlannableFailure>;
    /**
     * Haben ALLE gefragten Systeme geantwortet?
     *
     * Ausdrücklich genannt und nicht dem Leser überlassen — genau wie drüben in
     * `TaskHarvest::toArray()`. Eine verkürzte Liste muss als verkürzt
     * erkennbar sein, und zwar ohne sie aus zwei Feldern neu herzuleiten.
     */
    complete?: boolean;
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
    /**
     * Die Ausfallzeile je Grund — vier Lagen, vier nächste Schritte.
     *
     * Bis zum 11.11.2026 gab es EINE Zeile für alle. Das war dieselbe
     * Zusammenwerfung, die {@link PlannableFailureReason} auflöst: „Das System
     * bietet Aufgaben nicht an" ist kein Fehler, „keine Berechtigung" wird
     * durch Warten nicht besser, und „der Fremddienst hat einen Fehler
     * gemeldet" gehört gemeldet. Eine Zeile für alle vier sagt „etwas ist
     * schiefgelaufen" — und damit nichts.
     *
     * In jeder Vorlage werden `{system}` (der LOKALE Name) und `{message}`
     * ersetzt. `{reason}` bleibt als Platzhalter für den Wortlaut gültig —
     * unter diesem Namen stand er in der alten, einen Zeile, und ein Produkt,
     * das sie überschreibt, soll seine Überschreibung behalten.
     */
    unreachableText?: string;
    /** Für `not_offered` — kein Fehler, nur eine Auskunft. Darum ohne Wortlaut. */
    notOfferedText?: string;
    /** Für `not_allowed` — Wiederholen hilft nicht. */
    notAllowedText?: string;
    /** Für `tool_failed` — das gehört gemeldet. */
    toolFailedText?: string;
    /**
     * Für `reason: null` — niemand hat einen Grund genannt.
     *
     * Und deshalb steht hier der {@link PlannableFailure.message}: Er ist das
     * Einzige, was in diesem Fall etwas sagt. „Konnte nicht gefragt werden" ist
     * alles, was wir selbst wissen — eine Behauptung wie „nicht erreichbar"
     * wäre schon mehr, als geprüft ist.
     */
    unknownText?: string;
    /**
     * Der Knopf, der es nochmal versucht.
     *
     * Er erscheint NUR, wo {@link PlannableFailure.may_retry} es sagt — und das
     * sagt das Zielsystem, nicht diese Maske. Ein Wiederholen bei fehlender
     * Berechtigung ist ein leeres Versprechen: Es kostet eine zweite Wartezeit
     * und sagt danach dasselbe.
     */
    retryText?: string;
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
    notOfferedText = '{system} bietet keine Aufgaben an.',
    notAllowedText = 'Keine Berechtigung für {system} — {reason}',
    toolFailedText = '{system} hat einen Fehler gemeldet — {reason}',
    unknownText = 'Konnte nicht gefragt werden: {system} — {reason}',
    retryText = 'Erneut versuchen',
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
     * Wie oft von Hand nachgefragt wurde.
     *
     * Der Wiederhol-Knopf braucht keinen neuen Weg: Er zaehlt hier hoch, und
     * die Abfrage unten laeuft noch einmal ueber dieselbe `search`-Funktion,
     * die das Produkt ohnehin gibt. Ein eigener Rueckruf daneben waere ein
     * zweiter Weg zu derselben Stelle.
     */
    const [versuch, setVersuch] = useState(0);

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
    }, [begriff, search, source, versuch]);

    /** Die Systeme, die nicht nachgesehen werden konnten — mit Grund. */
    const ausfaelle = Object.entries(ernte?.failures ?? {});

    /**
     * Grund => Vorlage.
     *
     * Absichtlich eine vollstaendige Tafel und keine Kette von Abfragen: Kaeme
     * ein fuenfter Grund in die Union, meldet der Compiler HIER, dass ihm ein
     * Satz fehlt. Genau dafuer ist die Menge geschlossen.
     */
    const vorlagen: Record<PlannableFailureReason, string> = {
        not_offered: notOfferedText,
        not_allowed: notAllowedText,
        unreachable: unreachableText,
        tool_failed: toolFailedText,
    };

    /** Was an dem einen System steht — der Satz zu seinem Grund. */
    function ausfallZeile(schluessel: string, ausfall: PlannableFailure): string {
        // Ohne genannten Grund der Satz, der auf den Wortlaut zurueckfaellt:
        // Er ist das Einzige, was dann etwas sagt.
        const vorlage = ausfall.reason === null ? unknownText : vorlagen[ausfall.reason];

        return vorlage
            .replace('{system}', sourceNames?.[schluessel] ?? schluessel)
            // Beide Namen fuer denselben Wortlaut: `{reason}` hiess er in der
            // alten, einen Zeile, und eine Ueberschreibung von damals soll
            // weiter wirken.
            .replace('{reason}', ausfall.message)
            .replace('{message}', ausfall.message);
    }

    /**
     * Heisst ein leeres Ergebnis hier wirklich „nichts offen"?
     *
     * Die drei Zustaende, die NICHT dasselbe sind (Wiki #595):
     *
     * 1. Das System hat geantwortet und nichts gemeldet — das ist eine Antwort,
     *    und sie wird hingeschrieben.
     * 2. Das System steht in `failures` — dann steht der Satz zu seinem Grund
     *    da, nicht die Leermeldung. Ein Ausfall darf nicht als leere Liste
     *    erscheinen.
     * 3. Niemand hat geantwortet, ohne dass ein Ausfall gemeldet waere. Dann
     *    wissen wir nichts, und dann steht hier nichts. Eine Leermeldung waere
     *    eine Auskunft, die keiner geprueft hat.
     *
     * Sagt die Antwort nichts ueber die Systeme (`ok` fehlt oder alte Form),
     * bleibt es bei der alten Lesart — sonst verschwaende ein Produkt, das nur
     * Treffer schickt, ploetzlich seine Leermeldung.
     */
    const wirklichNichtsOffen =
        // Sagt die Ernte selbst, dass sie unvollstaendig ist, ist die Frage
        // beantwortet — und zwar mit nein. Das ist die Zusicherung, die
        // `complete` drueben gibt; sie hier nachzurechnen waere der zweite Weg
        // zu derselben Aussage.
        ernte?.complete === false
            ? false
            : ernte?.ok === undefined
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
                // `plannable-failed` heisst der Haken weiter, obwohl das Feld
                // jetzt `failures` heisst: Am Typ muss der Bruch laut sein, hier
                // waere er still — ein Produkt mit eigener Gestaltung an diesem
                // Haken stuende ohne Meldung da, ohne dass es jemand merkt.
                <div role="status" data-slot="plannable-failed" className="flex flex-col gap-1">
                    {ausfaelle.map(([schluessel, ausfall]) => (
                        <p
                            key={schluessel}
                            data-source={schluessel}
                            // Der Grund steht auch am Element: Eine Maske, die
                            // „nicht angeboten" anders zeichnen will als einen
                            // Fehler, soll den Satz nicht lesen muessen.
                            data-reason={ausfall.reason ?? ''}
                            className="text-muted-foreground flex flex-wrap items-center gap-2 text-xs"
                        >
                            <span>{ausfallZeile(schluessel, ausfall)}</span>

                            {/*
                                Der Knopf nur, wo Warten laut Zielsystem helfen
                                KANN. Bei fehlender Berechtigung waere er ein
                                leeres Versprechen — es kostet eine zweite
                                Wartezeit und sagt danach dasselbe.
                            */}
                            {ausfall.may_retry && (
                                <button
                                    type="button"
                                    data-action="retry"
                                    className="hover:text-foreground rounded-md border px-2 py-0.5 underline-offset-4 hover:underline"
                                    onClick={() => {
                                        // Die Meldung geht weg, BEVOR die neue
                                        // Antwort da ist: Solange gefragt wird,
                                        // wissen wir es nicht mehr. Die Treffer
                                        // der uebrigen Systeme bleiben stehen —
                                        // die waren richtig und sind es noch.
                                        setErnte(null);
                                        setGeantwortet(false);
                                        setVersuch((bisher) => bisher + 1);
                                    }}
                                >
                                    {retryText}
                                </button>
                            )}
                        </p>
                    ))}
                </div>
            )}
        </div>
    );
}

export default PlannableSearch;
