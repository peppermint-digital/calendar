import { useEffect, useId, useMemo, useRef, useState, type ReactNode } from 'react';
import { ComposerSteps, type ComposerStep, type ComposerStepsLabels } from './ComposerSteps';
import { DateField } from './DateField';
import { EventForm, type EventFormErrors, type EventFormLabels } from './EventForm';
import { KindPicker, type KindOption, type KindPickerLabels } from './KindPicker';
import { PlannableSearch, type Plannable, type PlannableHarvest } from './PlannableSearch';
import { TaskSourcePicker, type TaskSource } from './TaskSourcePicker';
import { TimeField } from './TimeField';
import { emptyDraft, type EventCategory, type EventDraft, type EventKind } from './types';
import { useRemoteTarget, type RemoteSource } from './useRemoteTarget';

export type EventComposerLabels = {
    /**
     * Die drei Wörter der alten Zielwahl.
     *
     * Sie werden seit dem 11.11.2026 NICHT mehr gezeichnet: Das System wird
     * nicht vorher gewählt, es ergibt sich aus der Terminart und steht klein an
     * deren Zeile ({@link KindPicker}). Sie bleiben im Typ, damit ein Produkt,
     * das sie noch übergibt, nicht bricht — mehr sind sie nicht.
     *
     * @deprecated Ohne Wirkung. Die Terminart trägt das System.
     */
    target: string;
    /** @deprecated Ohne Wirkung. Siehe {@link EventComposerLabels.target}. */
    ownCalendar: string;
    /** @deprecated Ohne Wirkung. Siehe {@link EventComposerLabels.target}. */
    targetHint: string;
    /** Die beiden Wege ueber der Maske. */
    modeEvent: string;
    modePlannable: string;
    /** Was unter den beiden Wegen steht, damit die Wahl ohne Raten getroffen wird. */
    modeEventHint: string;
    modePlannableHint: string;
    /** Die Beschriftungen der Schritte im Kopf des Assistenten. */
    stepWhat: string;
    stepKind: string;
    stepFields: string;
    /**
     * Die Beschriftung der EINEN Stufe, die Aufgabe und Zeitraum zusammen
     * abfragte.
     *
     * Seit dem 11.11.2026 sind es drei Stufen — Aufgabensystem, Aufgabe,
     * Zeitraum —, weil die Aufgabenliste nach dem System gefiltert sein muss,
     * um ueberhaupt lesbar zu sein (#9798). Die Beschriftung bleibt im Typ,
     * damit ein Produkt, das sie noch uebergibt, nicht bricht.
     *
     * @deprecated Ohne Wirkung. Siehe {@link EventComposerLabels.stepSlot}.
     */
    stepPlannable: string;
    /** Die Stufe, auf der das Aufgabensystem gewaehlt wird. */
    stepTaskSource: string;
    /** Die Stufe, auf der die Aufgabe gewaehlt wird. */
    stepTask: string;
    /** Die Stufe, auf der der Zeitraum bestaetigt wird. */
    stepSlot: string;
    /** Ueber dem Zeitraum im Aufgaben-Weg. */
    plannableSlot: string;
    /** Ueber der Suche. */
    plannablePick: string;
    /** Der Knopf, der die Aufgabe wirklich einplant. */
    plannableSchedule: string;
    /** Der Weg hinaus, wenn es die Aufgabe noch nicht gibt. */
    plannableNew: string;
    /** Ueber der Liste der Aufgabensysteme. */
    taskSourceHeading: string;
    /** Wenn kein Aufgabensystem angesteckt ist. */
    taskSourceEmpty: string;
};

const COMPOSER_DEFAULTS: EventComposerLabels = {
    target: 'Anlegen in',
    ownCalendar: 'Mein Kalender',
    targetHint:
        'Der Termin entsteht in {system} und gehört dort hin. Terminart und Felder kommen von dort — ' +
        'hier erscheint er danach als fremder Termin, angezeigt und nicht kopiert.',
    modeEvent: 'Termin',
    modePlannable: 'Aufgabe einplanen',
    modeEventHint: 'Ein Termin mit Titel, Zeit und Ort.',
    modePlannableHint: 'Eine Aufgabe, die es schon gibt, in den Kalender legen.',
    stepWhat: 'Was',
    stepKind: 'Terminart',
    stepFields: 'Angaben',
    stepPlannable: 'Aufgabe und Zeitraum',
    stepTaskSource: 'Aufgabensystem',
    stepTask: 'Aufgabe',
    stepSlot: 'Zeitraum',
    plannableSlot: 'Wann',
    plannablePick: 'Welche Aufgabe?',
    plannableSchedule: 'Einplanen',
    plannableNew: 'Neue Aufgabe anlegen',
    taskSourceHeading: 'Aus welchem System?',
    taskSourceEmpty: 'Hier ist kein Aufgabensystem angesteckt.',
};

export type EventComposerProps = {
    /** Die Arten, die DIESES System von Hand anlegen lässt. */
    kinds: EventKind[];
    /** Fremde Kalender, die Termine annehmen. Leer = nur der eigene. */
    targets?: RemoteSource[];
    /**
     * Ein Ziel, das von Anfang an gewaehlt ist.
     *
     * Fuer den Weg „woanders anlegen" aus einem Auswahldialog: Wer dort ein
     * fremdes System anklickt, hat die Wahl schon getroffen und soll sie nicht
     * in der Maske wiederholen muessen. Ohne Angabe beginnt die Maske beim
     * eigenen Kalender.
     */
    initialTarget?: string;
    /** Der zu ändernde Termin, oder `null` beim Anlegen. */
    value?: EventDraft | null;
    /**
     * Der laufende Entwurf — wenn das Produkt ihn selbst hält.
     *
     * Ohne diese beiden hält ihn der Composer, und das ist der einfache Fall.
     * Manche Masken brauchen ihn aber draußen: Der Manager spiegelt ihn über
     * einen Effekt in seine Nutzlast (`start_datetime` aus Datum und Uhrzeit,
     * `category_id`, die Serienregel) und seine Zusatzfelder lesen ihn mit.
     * Ein Composer, der den Entwurf einschlösse, zwänge solche Masken zu einem
     * zweiten Zustand daneben — und zwei Zustände für dieselbe Sache laufen
     * auseinander.
     */
    draft?: EventDraft;
    onDraftChange?: (draft: EventDraft) => void;
    /** Vorbelegung beim Anlegen — etwa aus einem Klick ins Raster. */
    preset?: Partial<EventDraft>;
    /**
     * Speichern im EIGENEN Kalender.
     *
     * Nur dieser Weg gehört dem Produkt: Es kennt seine Route, seine Nutzlast
     * und seine Zusatzfelder. Der fremde Weg liegt im Paket, weil er überall
     * derselbe ist.
     */
    onSaveHere: (draft: EventDraft) => void;
    onCancel?: () => void;
    /** Nach dem Anlegen in einem FREMDEN System. */
    onSavedThere?: () => void;

    /**
     * Felder, die nur dieses System kennt — Projekt, Aufgabe, Deal, Abrechnung.
     *
     * Sie werden **hinzugefügt**, nicht statt der gemeinsamen Maske gezeigt.
     * Bei einem fremden Ziel verschwinden sie: Wer aus dem CRM im Manager
     * anlegt, hat dort keinen Deal — ein Feld dafür nähme eine Eingabe
     * entgegen, die nirgends ankommt.
     */
    extraFields?: ReactNode;

    categories?: EventCategory[];
    categoryMode?: 'closed' | 'open';
    multiDay?: boolean;
    allowRecurrence?: boolean;
    renderTime?: (props: { id: string; value: string; onChange: (value: string) => void }) => ReactNode;
    submitting?: boolean;
    error?: string | null;
    fieldErrors?: EventFormErrors;
    /**
     * Alle Beschriftungen dieser Maske in EINEM Objekt — die der Felder, die
     * der Maske selbst und die der Schrittkette. Ein Produkt soll nicht
     * nachsehen muessen, welches Wort zu welchem Bauteil gehoert.
     */
    labels?: Partial<EventFormLabels & EventComposerLabels & ComposerStepsLabels & KindPickerLabels>;

    /**
     * Die Umhuellung der ABSCHNITTE, vom Produkt gestellt.
     *
     * Zielwahl und Formular sind zwei Abschnitte und bekommen je eine — so, wie
     * es in den Produkten vorher stand. Wer stattdessen den ganzen Composer in
     * eine Karte setzt und hier auch eine reicht, bekommt Karte in Karte.
     *
     * Das Paket zeichnet bewusst mit schlichten Elementen — eine Abhaengigkeit
     * auf eine Komponentensammlung zwaenge sie jedem Verbraucher auf. Das darf
     * aber nicht heissen, dass die Maske im Produkt fremd aussieht: Wer dort
     * `<Card>` verwendet, reicht sie hier herein.
     *
     * Ohne Angabe ein schlichter Rahmen — brauchbar, aber nicht die Gestaltung
     * des Produkts.
     */
    renderSection?: (children: ReactNode) => ReactNode;
    /** Wo die Routen des Pakets liegen. */
    /**
     * Der zweite Weg: eine BESTEHENDE Aufgabe einplanen.
     *
     * Ohne diese Angabe gibt es den Weg nicht — kein Moduswechsel, keine
     * Suche. Das ist die Modularitaet: Wo kein Aufgabensystem angesteckt ist,
     * gibt es nichts einzuplanen, und die Maske fragt nicht danach.
     *
     * `search` fragt die Stelle des Produkts (siehe {@link PlannableSearch}),
     * `onSchedule` legt den Block an und bekommt den Zeitraum dazu — denn der
     * steht hier und nicht im Entwurf der Maske.
     *
     * `newHref` ist der Weg hinaus, wenn es die Aufgabe noch nicht gibt: Eine
     * NEUE Aufgabe anzulegen gehoert dem Aufgabensystem, nicht dem Kalender.
     */
    plannable?: {
        /**
         * Die Aufgabensysteme, aus denen gewaehlt werden kann.
         *
         * Mit mehr als einem steht eine Stufe davor: erst das System, dann
         * dessen Aufgaben. Bastian am 11.11.2026: „sonst kriege ich ja einfach
         * eine unendlich lange Liste mit hunderten Aufgaben." Bei genau einem
         * entfaellt die Stufe — und ohne Angabe gibt es sie gar nicht, dann
         * fragt die Suche ohne Systembegriff, genau wie bisher.
         *
         * Die Liste ist LOKALE Angabe (im Aufgabenpaket
         * `TaskSourceRegistry::available()`, das niemanden fragt) und steht
         * deshalb schon beim Zeichnen da. Siehe {@link TaskSourcePicker} —
         * daran haengen zwei Zusicherungen dieser Maske.
         */
        sources?: TaskSource[];
        search: (begriff: string, source?: string) => Promise<Plannable[] | PlannableHarvest>;
        /**
         * Die Aufgabe wirklich einplanen.
         *
         * Der dritte Parameter sagt, WAS eingeplant wird und aus WELCHEM
         * System: Die Kennung allein genuegt nicht, sobald es mehrere Systeme
         * gibt — „7" im Manager und „7" in der Verwaltung sind zwei Aufgaben.
         * `source` ist leer, wo es keine Systemstufe gab; das ist dieselbe
         * Regel wie bei {@link KindOption} („leer = das eigene System").
         *
         * Additiv: Wer nur die ersten beiden Parameter nimmt, merkt nichts.
         */
        onSchedule: (
            id: number | string,
            slot: { date: string; start: string; end: string },
            choice?: { source: string; task: Plannable },
        ) => void;
        newHref?: string;
        /**
         * Laeuft das Einplanen gerade?
         *
         * Gelesen am Einplanen-Knopf, damit ein zweiter Klick nicht zwei
         * Bloecke anlegt. Bis zum 11.11.2026 fuehrte dieser Typ die Angabe und
         * NIEMAND las sie — ein Platz, den ein Produkt fuellen kann und den
         * keiner abholt, ist dasselbe Uebel wie ein Platz, aus dem gelesen und
         * den keiner fuellt (Wiki #595). Erst mit dem bestaetigenden Knopf gab
         * es die Stelle, an die sie gehoert.
         */
        busy?: boolean;
    };

    basePath?: string;
    /** Kopfzeilen für die Aufrufe ins andere System — CSRF und dergleichen. */
    headers?: () => Record<string, string>;
};

/**
 * Die vollständige Terminmaske: Terminart, Felder, Speichern.
 *
 * ## Warum das hier liegt und nicht in jedem Produkt
 *
 * Bis zum 15.10.2026 baute jedes System dieselben Teile selbst zusammen —
 * `useRemoteTarget`, `emptyDraft`, die Zielwahl, das Umschalten der Arten, den
 * Entwurfszustand. Gemessen am selben Tag:
 *
 *     CRM 301 Zeilen · Verwaltung 280 · Manager 728 · AI Brain 1348
 *
 * Dieselben Bausteine, vier Umgebungen. Die Folge war vorhersehbar und trat ein:
 * Eine Änderung am Verhalten brauchte vier Umbauten, der dritte wich ab, und der
 * vierte blieb liegen. Am 14.10. wurde #7760 in zwei Systemen ausgerollt und in
 * zweien nicht — der halbe Zustand ist schlechter als beide Enden.
 *
 * Hier liegt der Vorgang **einmal**. Ein Produkt liefert vier Dinge: seine
 * Arten, seine Ziele, seinen eigenen Speicherweg und seine Zusatzfelder.
 *
 * ## Wie das Ziel gewählt wird — und warum nicht mehr vorher
 *
 * Bis zum 11.11.2026 stand über der Maske eine Klappliste „Anlegen in": erst
 * das System, dann dessen Arten. Das verlangte eine Entscheidung, die niemand
 * treffen kann — in welchem System „Geschäftstermin" geführt wird, ist eine
 * Eigenschaft der Art und keine Frage an den Menschen.
 *
 * Jetzt steht zwischen „Was" und „Angaben" eine Stufe mit ALLEN anlegbaren
 * Arten aller Systeme in einer Liste ({@link KindPicker}); das System steht
 * klein an der Zeile. **Man wählt die Art, das Ziel ergibt sich daraus.**
 *
 * ## Was bei einem fremden Ziel anders ist
 *
 * Zwei Dinge, und beide folgen aus demselben Satz — drüben gelten die Regeln des
 * Zielsystems:
 *
 * 1. Die Maske zeichnet nach der **Zielart**, nicht nach den eigenen. Welche
 *    Felder sie trägt, sagt deren `requires`/`forbids`.
 * 2. Die Zusatzfelder verschwinden. Ein Deal-Feld beim Anlegen im Manager nähme
 *    eine Eingabe entgegen, die nirgends ankommt.
 *
 * ## Der Aufgaben-Weg: System, Aufgabe, Zeitraum
 *
 * Der zweite Weg fragt in drei Stufen: erst das Aufgabensystem, dann dessen
 * Aufgaben, dann den Zeitraum. Das ist die UMKEHRUNG der Terminart darüber, und
 * kein Widerspruch — dieselbe Regel bei anderer Größe. Zehn anlegbare
 * Terminarten passen in eine Liste, da steht das System klein daneben. Aufgaben
 * sind hunderte; Bastian am 11.11.2026: „sonst kriege ich ja einfach eine
 * unendlich lange Liste mit hunderten Aufgaben."
 *
 * Bei genau einem System entfällt die Stufe und ist damit beantwortet — die
 * Suche fragt dieses System. Ohne `plannable.sources` gibt es sie gar nicht, und
 * die Suche fragt ohne Systembegriff; das ist der Aufruf, den die Produkte heute
 * schon übergeben.
 *
 * Eingeplant wird erst durch den Knopf auf der letzten Stufe. Bis zum 11.11.2026
 * legte der Klick auf einen Treffer sofort an — ein Fehlgriff in der
 * Trefferliste erzeugte damit einen Eintrag, den niemand wollte, mit einem
 * Zeitraum, den niemand gesehen hatte.
 *
 * ## Beispiel
 *
 * ```tsx
 * <EventComposer
 *     kinds={Object.values(arten)}
 *     targets={ziele}
 *     value={termin}
 *     onSaveHere={(entwurf) => post('/kalender/termine', entwurf)}
 *     onCancel={() => router.visit('/kalender')}
 *     onSavedThere={() => router.visit('/kalender')}
 *     extraFields={<ProjektUndAufgabe … />}
 * />
 * ```
 */
export function EventComposer({
    kinds,
    targets = [],
    initialTarget,
    value = null,
    draft,
    onDraftChange,
    preset,
    onSaveHere,
    onCancel,
    onSavedThere,
    extraFields,
    categories,
    categoryMode,
    multiDay,
    allowRecurrence,
    renderTime,
    submitting = false,
    error = null,
    fieldErrors,
    labels,
    plannable,
    renderSection,
    basePath,
    headers,
}: EventComposerProps) {
    const beschriftung = { ...COMPOSER_DEFAULTS, ...labels };
    const bearbeiten = value !== null;

    /**
     * Die eigenen Arten, die ein Mensch auch waehlen DARF.
     *
     * Der Filter steht hier und nicht in vier Produkten. `creatable` reiste
     * vorher die ganze Strecke und wurde nirgends gelesen: PHP schickt es seit
     * je mit (`EventKind::toArray()`), der Typ fuehrt es, und die Maske gab die
     * Liste ungefiltert weiter — ein Platz, aus dem niemand liest, ist
     * dasselbe Uebel wie einer, den niemand fuellt (Wiki #595). Die Folge war
     * eine Auswahl, in der „Urlaub" und „Ressourcenbuchung" standen, obwohl
     * beide beim Speichern abgelehnt werden.
     *
     * `!== false` und nicht `=== true`: Eine Art ohne Angabe ist anlegbar, so
     * wie die PHP-Vorgabe es sagt. Alles andere liesse eine fehlende Angabe wie
     * ein Verbot aussehen.
     */
    const eigeneArten = kinds.filter((art) => art.creatable !== false);

    // Eigener Zustand nur, wenn das Produkt keinen mitbringt.
    const [innerer, setInnerer] = useState<EventDraft>(() =>
        // Vorbelegt mit der ersten ANLEGBAREN Art, nicht mit der ersten
        // ueberhaupt: Ein Produkt, das seine Liste in Registry-Reihenfolge
        // hereingibt, hat vorne vielleicht „Urlaub" stehen — und dann traegt
        // der Entwurf eine Art, die beim Speichern abgelehnt wird.
        value ?? { ...emptyDraft(eigeneArten[0]?.key), ...preset },
    );
    const gesteuert = draft !== undefined;
    const entwurf = gesteuert ? draft : innerer;
    const setEntwurf = (naechster: EventDraft) => {
        if (! gesteuert) {
            setInnerer(naechster);
        }

        onDraftChange?.(naechster);
    };

    const [eigenerFehler, setEigenerFehler] = useState<string | null>(null);

    // Eindeutig je Maske: Zwei Composer auf einer Seite duerfen nicht
    // dieselben Kennungen vergeben, sonst zeigt `aria-describedby` auf den
    // Hinweis der anderen.
    const kennung = useId();

    const fremd = useRemoteTarget({
        sources: targets,
        basePath,
        headers,
        onError: setEigenerFehler,
        onDone: () => onSavedThere?.(),
        // Die Arten ALLER Ziele schon beim Oeffnen, nicht erst beim Auswaehlen:
        // Die Artenliste zeigt sie alle auf einmal, und sie nacheinander zu
        // holen hiesse, sie erst nach der Wahl zu haben. Nur beim Anlegen —
        // beim Bearbeiten steht das System des Termins schon fest, und ein
        // Netzaufruf je Ziel waere dort fuer nichts.
        preloadKinds: ! bearbeiten,
    });

    const fremdesZiel = fremd.target !== '';

    /**
     * Welcher der beiden Wege gerade offen ist.
     *
     * Beim Bearbeiten gibt es die Wahl nicht: Da steht schon fest, was der
     * Eintrag ist. Ihn von einem Termin in eine eingeplante Aufgabe zu
     * verwandeln waere ein anderer Vorgang als ihn zu aendern — dieselbe
     * Begruendung wie bei der Terminart darunter.
     */
    const [weg, setWeg] = useState<'termin' | 'aufgabe'>('termin');

    /**
     * Der Zeitraum fuer den Aufgaben-Weg.
     *
     * Eigener Zustand neben dem Entwurf der Maske, weil hier nur drei Felder
     * gebraucht werden — Art, Titel, Ort und Beschreibung kommen aus der
     * Aufgabe. Eine Maske, die beide Saetze zeigt, fragt die Haelfte umsonst.
     */
    const [zeitraum, setZeitraum] = useState(() => ({
        // Aus dem Entwurf, nicht nur aus `preset`: Ein Produkt, das seinen
        // Entwurf selbst haelt (`draft`), gibt die Vorgabe schon dort hinein
        // — der markierte Zeitraum stuende sonst im Termin-Weg und fehlte im
        // Aufgaben-Weg daneben.
        date: entwurf.date || preset?.date || heute(),
        start: entwurf.start || preset?.start || '09:00',
        end: entwurf.end || preset?.end || '10:00',
    }));

    const aufgabenWeg = Boolean(plannable) && ! bearbeiten;

    /** Die Aufgabensysteme, aus denen gewaehlt werden kann. */
    const aufgabenSysteme = plannable?.sources ?? [];

    /** Ist ueberhaupt etwas zu waehlen? */
    const mehrereSysteme = aufgabenSysteme.length > 1;

    /**
     * Gibt es die Stufe „Aufgabensystem"?
     *
     * Nur bei mehr als einem System — eine Stufe mit einer Antwort wird nicht
     * gestellt, sondern beantwortet, wie ueberall in diesem Assistenten.
     *
     * Und hier genuegt die Zahl, anders als bei der Terminart: Die Systemliste
     * kommt NICHT ueber das Netz (im Aufgabenpaket `TaskSourceRegistry::available()`,
     * das niemanden fragt), sie steht beim Zeichnen schon da. Also kann sie
     * nicht nachtraeglich wachsen, und eine entfallene Stufe kann nicht
     * nachtraeglich aufspringen. Die Zusicherung „nicht ueberspringen, solange
     * die Antwort unterwegs ist" sitzt deshalb eine Stufe weiter, bei den
     * Aufgaben — dort wird wirklich gefragt. Wer diese Liste eines Tages ueber
     * das Netz holt, nimmt die Voraussetzung weg und muss die Regel hier
     * nachbauen.
     */
    const systemSchritt = aufgabenWeg && weg === 'aufgabe' && mehrereSysteme;

    const [eigenesSystem, setEigenesSystem] = useState('');

    /**
     * Das System, dessen Aufgaben gezeigt werden.
     *
     * Bei genau einem ist es dieses: Die Stufe entfaellt und ist damit
     * BEANTWORTET, nicht nur weg. Das braucht hier keinen Effekt wie bei der
     * Terminart — eine Systemwahl hat keine Nebenwirkung, sie wird nur
     * weitergegeben, und dann genuegt es, sie abzuleiten.
     */
    const gewaehltesSystem =
        aufgabenSysteme.length === 1 ? aufgabenSysteme[0]!.key : eigenesSystem;

    /**
     * Die gewaehlte Aufgabe.
     *
     * Sie steht hier und nicht im Entwurf: Ein Zeitblock traegt Titel, Ort und
     * Beschreibung der Aufgabe, nicht die der Maske — eine Maske, die beide
     * Saetze zeigt, fragt die Haelfte umsonst.
     */
    const [aufgabe, setAufgabe] = useState<Plannable | null>(null);

    /**
     * Schluessel => Name der Systeme, fuer die Ausfallmeldung an der Suche.
     *
     * Aus der LOKALEN Beschriftung, nie aus der Antwort des fremden Systems:
     * Die Herkunft setzt der Fragende, nicht der Befragte (Wiki #595).
     */
    const systemNamen = useMemo(
        () => Object.fromEntries(aufgabenSysteme.map((system) => [system.key, system.label])),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [plannable?.sources],
    );

    /** Ein System waehlen — und damit die Liste der naechsten Stufe bestimmen. */
    const systemWaehlen = (system: TaskSource) => {
        if (system.key !== gewaehltesSystem) {
            // Die Aufgabe des vorigen Systems ist jetzt keine Wahl mehr. Blieb
            // sie stehen, plante der Knopf auf der letzten Stufe etwas ein,
            // was auf der Stufe davor nicht mehr sichtbar war.
            setAufgabe(null);
        }

        setEigenesSystem(system.key);
        setSchritt('aufgabe');
    };

    /** Eine Aufgabe waehlen. Erst danach ist der Zeitraum eine Frage. */
    const aufgabeWaehlen = (kennung: number | string, eintrag?: Plannable) => {
        // Der Eintrag ist am Typ optional, weil ein Aufrufer der Suche nur die
        // Kennung melden darf. Von DIESER Suche kommt er immer mit.
        setAufgabe(eintrag ?? { id: kennung, title: '' });
        setSchritt('details');
    };

    /** Die gewaehlte Aufgabe in den gewaehlten Zeitraum legen. */
    const einplanen = () => {
        if (! aufgabe) {
            return;
        }

        plannable?.onSchedule(aufgabe.id, zeitraum, { source: gewaehltesSystem, task: aufgabe });
    };


    /**
     * Ein vorgewaehltes Ziel engt die LISTE ein, es nimmt keine Stufe weg.
     *
     * So uebersetzt sich `initialTarget` in die neue Welt: Wer aus einem
     * Auswahldialog mit angeklicktem System kommt, waehlt das System nicht
     * wieder — er sieht nur noch die Arten dieses Systems. Die Art muss er
     * weiter waehlen, denn danach hat ihn niemand gefragt. Hat das System genau
     * eine, faellt die Stufe von selbst weg.
     */
    const nurEinZiel = initialTarget !== undefined && initialTarget !== '';

    /**
     * Alle waehlbaren Arten in EINER Liste — die eigenen zuerst, dann die
     * fremden in der Reihenfolge, in der das Produkt seine Ziele nennt.
     *
     * Bewusst nicht nach System sortiert und ohne Gruppenkoepfe: Das zerlegte
     * die Liste wieder in die Produktkaestchen, die sie gerade aufloest
     * (Wiki #595). Das System ist Beschriftung, nicht Gliederung.
     */
    const artenwahl: KindOption[] = [];

    if (! nurEinZiel) {
        for (const art of eigeneArten) {
            artenwahl.push({ source: '', system: '', kind: art });
        }
    }

    for (const ziel of fremd.writableSources) {
        if (nurEinZiel && ziel.key !== initialTarget) {
            continue;
        }

        for (const art of fremd.kindsBySource[ziel.key] ?? []) {
            if (art.creatable === false) {
                continue;
            }

            // Der Systemname kommt aus der LOKALEN Beschriftung der Quelle,
            // nie aus der Antwort des fremden Systems: Die Herkunft setzt der
            // Fragende, nicht der Befragte (Wiki #595).
            artenwahl.push({ source: ziel.key, system: ziel.label, kind: art });
        }
    }

    /** Ausgefallene Ziele — mit Namen, nicht mit Schluessel. */
    const ausgefalleneZiele = fremd.unreachableSources
        .filter((schluessel) => ! nurEinZiel || schluessel === initialTarget)
        .map((schluessel) => targets.find((ziel) => ziel.key === schluessel)?.label ?? schluessel);

    /**
     * Gibt es die Stufe „Terminart" ueberhaupt?
     *
     * Drei Gruende, sie zu zeigen, und der dritte ist der wichtige:
     *
     * 1. Es gibt mehr als eine Art — dann ist etwas zu waehlen.
     * 2. Die Arten der fremden Systeme sind noch unterwegs. Waere sie hier
     *    weg, sprang sie nach dem Laden nachtraeglich auf, waehrend der Mensch
     *    schon im Formular tippt.
     * 3. Ein Ziel hat nicht geantwortet. Dann MUSS die Stufe stehen, auch wenn
     *    nur eine Art uebrig ist: Ein uebersprungener Schritt verschweigt den
     *    Ausfall, und „ich konnte nicht nachsehen" ist eine eigene Aussage
     *    (Wiki #595).
     *
     * Beim Bearbeiten nie: Der Termin hat seine Art schon. Und im Aufgaben-Weg
     * nie — dort entsteht ein Zeitblock im System der Aufgabe.
     */
    const artWaehlbar =
        ! bearbeiten && (artenwahl.length > 1 || fremd.loadingKinds || ausgefalleneZiele.length > 0);

    const artSchritt = artWaehlbar && weg === 'termin';

    /**
     * Auf welche Stufe die Weiche fuehrt.
     *
     * Gefragt wird mit dem Weg, der gerade GEWAEHLT wurde, nicht mit dem, der
     * noch offen ist. Das ist kein Feinschliff: `artSchritt` und `systemSchritt`
     * lesen beide `weg`, und der steht im Augenblick des Klicks noch auf dem
     * alten Wert. Mit dem gerechnet fuehrte „Termin" nach einem Abstecher in den
     * Aufgaben-Weg an der Terminart VORBEI — direkt ins Formular, mit der Art,
     * die zufaellig im Entwurf stand. Gefunden am 11.11.2026 beim Einbau der
     * Aufgaben-Stufen, wo derselbe Fehler den Weg an der Systemwahl vorbeigefuehrt
     * haette.
     */
    const stufeNach = (gewaehlterWeg: 'termin' | 'aufgabe'): string => {
        if (gewaehlterWeg === 'termin') {
            return artWaehlbar ? 'art' : 'details';
        }

        // Auf 'details' ginge es nie direkt: Dort steht der Zeitraum, und zu
        // dem gehoert eine Aufgabe.
        return mehrereSysteme ? 'aufgabensystem' : 'aufgabe';
    };

    /** Die getroffene Wahl, in der Form, in der die Liste sie fuehrt. */
    const gewaehlt = {
        source: fremd.target,
        kind: fremdesZiel ? fremd.targetKind : entwurf.kind,
    };

    const gewaehlteArt = artenwahl.find(
        (eintrag) => eintrag.source === gewaehlt.source && eintrag.kind.key === gewaehlt.kind,
    );

    /** Was im Kopf des Assistenten steht — „Geschaeftlich (Manager)". */
    const gewaehlterName = gewaehlteArt
        ? gewaehlteArt.system === ''
            ? gewaehlteArt.kind.label
            : `${gewaehlteArt.kind.label} (${gewaehlteArt.system})`
        : undefined;

    /**
     * Eine Art waehlen — und damit auch das System, in dem sie gefuehrt wird.
     *
     * Das ist die ganze Umkehrung: Vorher waehlte man ein System und bekam
     * dessen Arten, jetzt waehlt man eine Art und das System ergibt sich.
     * Nach aussen bleibt es derselbe Zustand wie vorher (`target` und
     * `targetKind` in {@link useRemoteTarget}) — sonst gaebe es die Angabe
     * zweimal, und das Anlegen drueben liest nur eine davon.
     */
    const artWaehlen = (eintrag: KindOption, weiter = true) => {
        if (eintrag.source === '') {
            // Zurueck auf den eigenen Kalender: Sonst stuende die Art eines
            // fremden Systems an einem eigenen Termin.
            void fremd.chooseTarget('');
            setEntwurf({ ...entwurf, kind: eintrag.kind.key });
        } else {
            // Kein zweiter Netzweg: Die Arten dieses Ziels sind vorgeladen,
            // `chooseTarget` nimmt sie aus dem Speicher.
            void fremd.chooseTarget(eintrag.source, eintrag.kind.key);
        }

        if (weiter) {
            setSchritt('details');
        }
    };

    /**
     * Eine Stufe mit einer einzigen Antwort wird nicht gestellt — sie wird
     * beantwortet.
     *
     * Der Fall, auf den es ankommt: Verwaltung und CRM fuehren je EINE
     * anlegbare Art. Eine Liste mit einem Eintrag ist eine Frage, die keine
     * ist. Dasselbe, wenn ein `initialTarget` auf ein System zeigt, das nur
     * eine Art hat.
     *
     * Der Haken merkt sich, was er zuletzt gesetzt hat: Ohne das rief er sich
     * nach jedem Rendern erneut, weil `artenwahl` jedes Mal neu entsteht.
     */
    const artAutomatisch = useRef('');

    useEffect(() => {
        if (bearbeiten || artSchritt || artenwahl.length !== 1) {
            return;
        }

        const einzige = artenwahl[0]!;
        const griff = `${einzige.source}:${einzige.kind.key}`;

        if (artAutomatisch.current === griff) {
            return;
        }

        if (einzige.source === gewaehlt.source && einzige.kind.key === gewaehlt.kind) {
            return;
        }

        artAutomatisch.current = griff;
        artWaehlen(einzige, false);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [artSchritt, artenwahl.length, bearbeiten, gewaehlt.kind, gewaehlt.source]);

    /**
     * Nach WELCHER Art zeichnet die Maske?
     *
     * Bei fremdem Ziel nach dessen Art — sonst zeigte sie die eigenen Felder,
     * während drüben ganz andere gelten. Welche Felder sie trägt, sagt
     * `requires`/`forbids` der gewählten Art.
     */
    const sichtbarerEntwurf = useMemo(
        () => (fremdesZiel ? { ...entwurf, kind: fremd.targetKind } : entwurf),
        [entwurf, fremd.targetKind, fremdesZiel],
    );

    /**
     * Was das Formular als Artenliste bekommt.
     *
     * Beim ANLEGEN genau die gewaehlte Art — und damit verbirgt `EventForm`
     * seine Auswahlliste von selbst (sie erscheint erst ab zwei Eintraegen).
     * Das ist der Sinn des Schritts: Wer die Art gerade gewaehlt hat, soll sie
     * nicht gleich darunter noch einmal waehlen.
     *
     * Beim BEARBEITEN die ganze Liste — dort ist die Art zu aendern ein
     * gueltiger Vorgang. Gefiltert, ABER die Art des Termins bleibt drin, auch
     * wenn sie nicht anlegbar ist: `creatable` sagt „darf man das auswaehlen",
     * nicht „darf das existieren". Ein eingeplanter Block, dessen Art aus der
     * Liste faellt, waere ein Formular ohne Regeln — und ohne Felder.
     */
    const formularArten = bearbeiten
        ? kinds.filter((art) => art.creatable !== false || art.key === entwurf.kind)
        : gewaehlteArt
          ? [gewaehlteArt.kind]
          : (fremdesZiel ? fremd.targetKinds : eigeneArten).filter((art) => art.creatable !== false);

    const geaendert = (naechster: EventDraft) => {
        setEntwurf(naechster);

        if (fremdesZiel && naechster.kind !== fremd.targetKind) {
            fremd.setTargetKind(naechster.kind);
        }
    };

    const speichern = () => {
        setEigenerFehler(null);

        if (fremdesZiel) {
            void fremd.createThere({
                date: entwurf.date,
                start: entwurf.allDay ? undefined : entwurf.start,
                end: entwurf.allDay ? undefined : entwurf.end,
                allDay: entwurf.allDay,
                title: entwurf.title,
                location: entwurf.location,
                description: entwurf.description,
                meetingUrl: entwurf.meetingUrl,
            });

            return;
        }

        onSaveHere(entwurf);
    };

    /**
     * Die Schritte, die es in DIESER Maske gibt.
     *
     * Sie haengen daran, was das Produkt mitbringt und was gewaehlt wurde —
     * deshalb stehen sie hier und nicht in {@link ComposerSteps}. Ohne
     * angestecktes Aufgabensystem gibt es nichts zu fragen, und bei einer
     * einzigen Art nichts zu waehlen; bleibt ein Schritt uebrig, zeichnet sich
     * die Kette gar nicht.
     *
     * Beim BEARBEITEN gibt es die Wahl ohnehin nicht — der Eintrag ist schon,
     * was er ist.
     */
    const schritte = useMemo<ComposerStep[]>(() => {
        const liste: ComposerStep[] = [];

        if (aufgabenWeg) {
            liste.push({
                key: 'was',
                label: beschriftung.stepWhat,
                chosen: weg === 'termin' ? beschriftung.modeEvent : beschriftung.modePlannable,
            });
        }

        if (artSchritt) {
            liste.push({ key: 'art', label: beschriftung.stepKind, chosen: gewaehlterName });
        }

        if (systemSchritt) {
            liste.push({
                key: 'aufgabensystem',
                label: beschriftung.stepTaskSource,
                chosen: systemNamen[gewaehltesSystem],
            });
        }

        if (aufgabenWeg && weg === 'aufgabe') {
            liste.push({
                key: 'aufgabe',
                label: beschriftung.stepTask,
                // Der Titel bleibt im Kopf stehen, waehrend der Zeitraum
                // bestaetigt wird: Sonst muesste man zurueckspringen, um
                // nachzusehen, was man gerade einplant.
                chosen: aufgabe?.title || undefined,
            });
        }

        liste.push({
            key: 'details',
            label: weg === 'aufgabe' && aufgabenWeg ? beschriftung.stepSlot : beschriftung.stepFields,
        });

        return liste;
    }, [
        artSchritt,
        aufgabe?.title,
        aufgabenWeg,
        beschriftung.modeEvent,
        beschriftung.modePlannable,
        beschriftung.stepFields,
        beschriftung.stepKind,
        beschriftung.stepSlot,
        beschriftung.stepTask,
        beschriftung.stepTaskSource,
        beschriftung.stepWhat,
        gewaehlterName,
        gewaehltesSystem,
        systemNamen,
        systemSchritt,
        weg,
    ]);

    const [schritt, setSchritt] = useState<string>(() =>
        aufgabenWeg ? 'was' : artSchritt ? 'art' : 'details',
    );

    /**
     * Auf einem Schritt stehen, den es nicht mehr gibt, ist eine leere Maske.
     *
     * Passiert, sobald ein Produkt `plannable` erst nachliefert oder wieder
     * wegnimmt, und seit dem 11.11.2026 auch, wenn die Terminart-Stufe
     * wegfaellt, weil am Ende nur eine Art uebrig blieb.
     *
     * Ausgewichen wird nach VORN, auf die letzte Stufe. Nach hinten waere
     * falsch: Eine Stufe verschwindet, weil es dort nichts mehr zu fragen gibt
     * — wer auf ihr stand, ist weiter, nicht zurueck. Und bei drei Stufen
     * schickte der Sprung auf die erste jemanden von der Terminart zurueck zur
     * Weiche, die er gerade beantwortet hat.
     */
    useEffect(() => {
        if (! schritte.some((eintrag) => eintrag.key === schritt)) {
            setSchritt(schritte[schritte.length - 1]!.key);
        }
    }, [schritt, schritte]);

    /** Die Umhuellung des Produkts — oder ein schlichter Rahmen. */
    const umhuellen = (kinder: ReactNode): ReactNode =>
        renderSection ? renderSection(kinder) : <div className="rounded-lg border p-4">{kinder}</div>;

    return (
        <div className="space-y-4">
            {/*
                Der Kopf des Assistenten. Er zeichnet sich selbst nicht, solange
                es nur einen Schritt gibt — siehe ComposerSteps.
            */}
            <ComposerSteps steps={schritte} current={schritt} onGoTo={setSchritt} labels={labels} />

            {/*
                Schritt 1: die Weiche. Sie bestimmt, welche Felder danach
                ueberhaupt gebraucht werden — deshalb steht sie davor und nicht
                daneben.

                Zwei grosse Flaechen statt zweier schmaler Knoepfe: Auf dieser
                Stufe ist die Wahl das Einzige auf dem Schirm, und auf einem
                Telefon ist eine Flaeche, die man trifft, mehr wert als eine,
                die Platz spart. Jede traegt ihre Erklaerung, damit die Wahl
                nicht aus zwei Wörtern geraten werden muss.
            */}
            {schritt === 'was' && umhuellen(
                <div className="flex flex-col gap-2 sm:flex-row">
                    {(['termin', 'aufgabe'] as const).map((eintrag) => {
                        const name =
                            eintrag === 'termin' ? beschriftung.modeEvent : beschriftung.modePlannable;
                        const erklaerung =
                            eintrag === 'termin'
                                ? beschriftung.modeEventHint
                                : beschriftung.modePlannableHint;

                        return (
                            <button
                                key={eintrag}
                                type="button"
                                data-mode={eintrag}
                                onClick={() => {
                                    setWeg(eintrag);
                                    // Der Termin-Weg fuehrt auf die Terminart,
                                    // wenn es dort etwas zu waehlen gibt — der
                                    // Aufgaben-Weg nie: Dort entsteht ein
                                    // Zeitblock im System der Aufgabe, und eine
                                    // Terminart hat er nicht.
                                    setSchritt(stufeNach(eintrag));
                                }}
                                aria-pressed={weg === eintrag}
                                // Der Name ist das WORT, die Erklaerung eine
                                // Beschreibung. Ohne diese Trennung zieht der
                                // Knopf seinen ganzen Inhalt in den Namen, und
                                // ein Vorleser sagt „Aufgabe einplanen Eine
                                // Aufgabe, die es schon gibt, in den Kalender
                                // legen" — als waere das der Name.
                                aria-label={name}
                                aria-describedby={`${kennung}-${eintrag}-hinweis`}
                                className={
                                    'flex-1 rounded-md border p-4 text-left hover:bg-muted ' +
                                    (weg === eintrag ? 'border-primary' : '')
                                }
                            >
                                <span className="block text-sm font-medium">{name}</span>
                                <span
                                    id={`${kennung}-${eintrag}-hinweis`}
                                    className="mt-1 block text-xs text-muted-foreground"
                                >
                                    {erklaerung}
                                </span>
                            </button>
                        );
                    })}
                </div>,
            )}

            {/*
                Schritt „Terminart": alle Arten aller Systeme in EINER Liste.

                Hier stand bis zum 11.11.2026 eine Klappliste „Anlegen in" ueber
                dem Formular — erst das System, dann dessen Arten. Die Reihenfolge
                war falsch herum: Wer einen Geschaeftstermin anlegen will, weiss,
                dass es ein Geschaeftstermin ist; in welchem System diese Art
                gefuehrt wird, ist eine Eigenschaft der Art und keine Frage an den
                Menschen. Jetzt waehlt er die Art, und das System steht klein
                daneben.
            */}
            {schritt === 'art' && umhuellen(
                <KindPicker
                    options={artenwahl}
                    value={gewaehlt}
                    onPick={artWaehlen}
                    unreachable={ausgefalleneZiele}
                    loading={fremd.loadingKinds}
                    labels={labels}
                />,
            )}

            {/*
                Schritt „Aufgabensystem": erst das System, dann dessen Aufgaben.

                Genau umgekehrt zur Terminart darueber — und das ist kein
                Widerspruch, sondern dieselbe Regel bei anderer Groesse. Zehn
                Terminarten passen in eine Liste, hunderte Aufgaben nicht.
                Bastian am 11.11.2026: „Bei Aufgaben ist es wahrscheinlich
                besser, wenn wir Aufgabesystem und dann Aufgabe machen."
            */}
            {schritt === 'aufgabensystem' && umhuellen(
                <TaskSourcePicker
                    options={aufgabenSysteme}
                    value={gewaehltesSystem}
                    onPick={systemWaehlen}
                    // Einzeln zugewiesen und nicht als ganzer Beutel wie bei
                    // KindPicker: Beide Bauteile haetten eine „heading" und
                    // eine „empty", und in einem gemeinsamen Beutel waere das
                    // EIN Wort fuer zwei Fragen.
                    labels={{
                        heading: beschriftung.taskSourceHeading,
                        empty: beschriftung.taskSourceEmpty,
                    }}
                />,
            )}

            {/*
                Schritt „Aufgabe": die Aufgaben des gewaehlten Systems.

                Mit Suchfeld, denn nach dem System sind es noch viele — und ohne
                jedes Feld fuer eine PERSON: Die angemeldete Person ist die
                Person, und eine Maske, die nach fremden Aufgaben suchen laesst,
                laedt zu einem Vorgang ein, den das Zielsystem ablehnen muss.
            */}
            {schritt === 'aufgabe' && umhuellen(
                <div className="space-y-4">
                    <div className="space-y-2">
                        <p className="text-sm font-medium">{beschriftung.plannablePick}</p>
                        <PlannableSearch
                            search={plannable!.search}
                            source={gewaehltesSystem}
                            sourceNames={systemNamen}
                            onPick={aufgabeWaehlen}
                        />
                    </div>

                    {/*
                        Eine NEUE Aufgabe anzulegen gehoert nicht hierher:
                        Titel, Prioritaet und Projekt sind Begriffe des
                        Aufgabensystems. Der Zeitraum reist in der Adresse
                        mit, damit er beim Zurueckkommen nicht fehlt — er ist
                        hier schon vorbelegt, auch wenn er erst auf der
                        naechsten Stufe bestaetigt wird.
                    */}
                    {plannable!.newHref && (
                        <a
                            href={`${plannable!.newHref}${plannable!.newHref.includes('?') ? '&' : '?'}date=${zeitraum.date}&start=${zeitraum.start}&end=${zeitraum.end}`}
                            className="inline-flex text-sm underline underline-offset-4"
                        >
                            {beschriftung.plannableNew}
                        </a>
                    )}
                </div>,
            )}

            {schritt !== 'details' ? null : aufgabenWeg && weg === 'aufgabe'
                ? /*
                      Letzte Stufe im Aufgaben-Weg: der Zeitraum, und der Knopf,
                      der wirklich einplant.

                      Bis zum 11.11.2026 plante der Klick auf einen Treffer
                      SOFORT ein. Das war nicht nur die falsche Reihenfolge,
                      sondern ein Fehlklick-Risiko: Wer in einer Trefferliste
                      danebengreift, hatte einen Termin erzeugt, den er nicht
                      wollte. Jetzt bestaetigt ein Knopf.

                      Der Zeitraum ist dabei meistens VORBELEGT — aus einem Klick
                      ins Raster (`preset`) oder aus dem Entwurf des Produkts.
                      Deshalb stehen die Felder mit ihrem Wert da und der Knopf
                      daneben: Wer aus dem Raster kommt, soll einmal bestaetigen
                      und nicht noch einmal eingeben.
                  */
                  umhuellen(
                      <div className="space-y-4" data-slot="plannable-slot">
                          <div className="space-y-2">
                              <p className="text-sm font-medium">{beschriftung.plannableSlot}</p>

                              {/* Was hier eingeplant wird, steht neben dem
                                  Knopf, nicht nur klein oben in der Kette. */}
                              {aufgabe && (
                                  <p
                                      data-slot="plannable-chosen"
                                      className="text-sm text-muted-foreground"
                                  >
                                      {aufgabe.title}
                                  </p>
                              )}

                              <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                  <DateField
                                      value={zeitraum.date}
                                      onChange={(datum) => setZeitraum((bisher) => ({ ...bisher, date: datum }))}
                                      className="sm:w-40"
                                  />
                                  <TimeField
                                      value={zeitraum.start}
                                      onChange={(zeit) => setZeitraum((bisher) => ({ ...bisher, start: zeit }))}
                                  />
                                  <TimeField
                                      value={zeitraum.end}
                                      onChange={(zeit) => setZeitraum((bisher) => ({ ...bisher, end: zeit }))}
                                  />

                                  <button
                                      type="button"
                                      data-action="schedule"
                                      onClick={einplanen}
                                      // Ohne gewaehlte Aufgabe gesperrt: Diese
                                      // Stufe ist ueber die Kette nicht
                                      // vorwaerts erreichbar, aber ein Knopf,
                                      // der im Nichts anlegt, waere schlimmer
                                      // als ein gesperrter.
                                      disabled={! aufgabe || Boolean(plannable!.busy)}
                                      className="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground disabled:opacity-50"
                                  >
                                      {beschriftung.plannableSchedule}
                                  </button>
                              </div>
                          </div>
                      </div>,
                  )
                : umhuellen(
                <EventForm
                    kinds={formularArten}
                    categories={categories}
                    categoryMode={categoryMode}
                    multiDay={multiDay}
                    allowRecurrence={allowRecurrence}
                    renderTime={renderTime}
                    value={sichtbarerEntwurf}
                    onChange={geaendert}
                    onSubmit={speichern}
                    onCancel={onCancel}
                    // Systemeigenes nur im eigenen Kalender: Drüben gelten die
                    // Regeln des Zielsystems, und ein Feld, dessen Eingabe
                    // nirgends ankommt, ist schlechter als keines.
                    extraFields={fremdesZiel ? undefined : extraFields}
                    submitting={submitting || fremd.busy}
                    error={eigenerFehler ?? error}
                    fieldErrors={fieldErrors}
                    labels={labels}
                />,
            )}
        </div>
    );
}

/** Heute als `YYYY-MM-DD` — in der Zeitzone des Geraets, nicht in UTC. */
function heute(): string {
    const jetzt = new Date();

    return [
        jetzt.getFullYear(),
        String(jetzt.getMonth() + 1).padStart(2, '0'),
        String(jetzt.getDate()).padStart(2, '0'),
    ].join('-');
}
