import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { DateField } from './DateField';
import { EventForm, type EventFormErrors, type EventFormLabels } from './EventForm';
import { PlannableSearch, type Plannable } from './PlannableSearch';
import { TimeField } from './TimeField';
import { emptyDraft, type EventCategory, type EventDraft, type EventKind } from './types';
import { useRemoteTarget, type RemoteSource } from './useRemoteTarget';

export type EventComposerLabels = {
    /** Beschriftung der Zielwahl. */
    target: string;
    /** Der Eintrag für den eigenen Kalender. */
    ownCalendar: string;
    /** Erklärung unter der Zielwahl. `{system}` wird ersetzt. */
    targetHint: string;
    /** Die beiden Wege ueber der Maske. */
    modeEvent: string;
    modePlannable: string;
    /** Ueber dem Zeitraum im Aufgaben-Weg. */
    plannableSlot: string;
    /** Ueber der Suche. */
    plannablePick: string;
    /** Der Weg hinaus, wenn es die Aufgabe noch nicht gibt. */
    plannableNew: string;
};

const COMPOSER_DEFAULTS: EventComposerLabels = {
    target: 'Anlegen in',
    ownCalendar: 'Mein Kalender',
    targetHint:
        'Der Termin entsteht in {system} und gehört dort hin. Terminart und Felder kommen von dort — ' +
        'hier erscheint er danach als fremder Termin, angezeigt und nicht kopiert.',
    modeEvent: 'Termin',
    modePlannable: 'Aufgabe einplanen',
    plannableSlot: 'Wann',
    plannablePick: 'Was',
    plannableNew: 'Neue Aufgabe anlegen',
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
    labels?: Partial<EventFormLabels & EventComposerLabels>;

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
        search: (begriff: string) => Promise<Plannable[]>;
        onSchedule: (id: number | string, slot: { date: string; start: string; end: string }) => void;
        newHref?: string;
        busy?: boolean;
    };

    basePath?: string;
    /** Kopfzeilen für die Aufrufe ins andere System — CSRF und dergleichen. */
    headers?: () => Record<string, string>;
};

/**
 * Die vollständige Terminmaske: Zielwahl, Felder, Speichern.
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

    // Eigener Zustand nur, wenn das Produkt keinen mitbringt.
    const [innerer, setInnerer] = useState<EventDraft>(() =>
        value ?? { ...emptyDraft(kinds[0]?.key), ...preset },
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

    const fremd = useRemoteTarget({
        sources: targets,
        basePath,
        headers,
        onError: setEigenerFehler,
        onDone: () => onSavedThere?.(),
    });

    // Das Startziel einmal waehlen, nicht bei jedem Rendern: `chooseTarget`
    // holt die Arten des Zielsystems, und das ist ein Netzweg.
    const [startzielGewaehlt, setStartzielGewaehlt] = useState(false);

    useEffect(() => {
        if (startzielGewaehlt || ! initialTarget) {
            return;
        }

        setStartzielGewaehlt(true);
        void fremd.chooseTarget(initialTarget);
    }, [fremd, initialTarget, startzielGewaehlt]);

    const fremdesZiel = fremd.target !== '';

    /**
     * Nach WELCHER Art zeichnet die Maske?
     *
     * Bei fremdem Ziel nach dessen Art — sonst zeigte sie die eigenen Felder,
     * während drüben ganz andere gelten. Die Artenwahl der Maske IST dann die
     * Wahl der Zielart; eine zweite Auswahlliste daneben liefe auseinander,
     * sobald jemand nur eine davon anfasst.
     */
    const sichtbareArten = fremdesZiel ? fremd.targetKinds : kinds;
    const sichtbarerEntwurf = useMemo(
        () => (fremdesZiel ? { ...entwurf, kind: fremd.targetKind } : entwurf),
        [entwurf, fremd.targetKind, fremdesZiel],
    );

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

    const zielname = targets.find((ziel) => ziel.key === fremd.target)?.label ?? '';

    /**
     * Welcher der beiden Wege gerade offen ist.
     *
     * Beim Bearbeiten gibt es die Wahl nicht: Da steht schon fest, was der
     * Eintrag ist. Ihn von einem Termin in eine eingeplante Aufgabe zu
     * verwandeln waere ein anderer Vorgang als ihn zu aendern — dieselbe
     * Begruendung wie bei der Zielwahl darueber.
     */
    const [weg, setWeg] = useState<'termin' | 'aufgabe'>('termin');

    /**
     * Der Zeitraum fuer den Aufgaben-Weg.
     *
     * Eigener Zustand neben dem Entwurf der Maske, weil hier nur drei Felder
     * gebraucht werden — Art, Titel, Ort und Beschreibung kommen aus der
     * Aufgabe. Eine Maske, die beide Saetze zeigt, fragt die Haelfte umsonst.
     */
    const [zeitraum, setZeitraum] = useState({
        date: preset?.date ?? heute(),
        start: preset?.start ?? '09:00',
        end: preset?.end ?? '10:00',
    });

    const aufgabenWeg = Boolean(plannable) && ! bearbeiten;

    /** Die Umhuellung des Produkts — oder ein schlichter Rahmen. */
    const umhuellen = (kinder: ReactNode): ReactNode =>
        renderSection ? renderSection(kinder) : <div className="rounded-lg border p-4">{kinder}</div>;

    return (
        <div className="space-y-4">
            {/*
                Die Zielwahl steht VOR der Maske und nur beim Anlegen: Ein
                Termin, den es schon gibt, gehört bereits einem System. Ihn
                umzuziehen wäre ein anderer Vorgang als ihn zu ändern, und die
                Maske ließe beides gleich aussehen.
            */}
            {!bearbeiten && fremd.writableSources.length > 0 && umhuellen(
                <div className="space-y-2">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                        <label htmlFor="event-composer-target" className="text-sm font-medium whitespace-nowrap">
                            {beschriftung.target}
                        </label>
                        <select
                            id="event-composer-target"
                            value={fremd.target}
                            onChange={(ereignis) => void fremd.chooseTarget(ereignis.target.value)}
                            // `bg-background`, nicht `bg-transparent`: Ein
                            // durchsichtiges Auswahlfeld nimmt im Dunkelmodus die
                            // Farbe dessen an, was zufaellig dahinterliegt.
                            className="h-9 w-full rounded-md border bg-background px-2 text-sm sm:max-w-xs"
                        >
                            <option value="">{beschriftung.ownCalendar}</option>
                            {fremd.writableSources.map((ziel) => (
                                <option key={ziel.key} value={ziel.key}>
                                    {ziel.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    {fremdesZiel && (
                        <p className="text-xs text-muted-foreground">
                            {beschriftung.targetHint.replace('{system}', zielname)}
                        </p>
                    )}
                </div>,
            )}

            {/*
                Die Wahl steht VOR den Feldern, weil sie bestimmt, welche
                gebraucht werden. Zwei Knoepfe, kein Dialog: Ein Dialog davor
                waere ein Klick mehr fuer den haeufigeren der beiden Wege.
            */}
            {aufgabenWeg && umhuellen(
                <div className="flex gap-2">
                    {(['termin', 'aufgabe'] as const).map((eintrag) => (
                        <button
                            key={eintrag}
                            type="button"
                            onClick={() => setWeg(eintrag)}
                            aria-pressed={weg === eintrag}
                            className={
                                weg === eintrag
                                    ? 'h-9 flex-1 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground'
                                    : 'h-9 flex-1 rounded-md border px-3 text-sm font-medium hover:bg-muted'
                            }
                        >
                            {eintrag === 'termin' ? beschriftung.modeEvent : beschriftung.modePlannable}
                        </button>
                    ))}
                </div>,
            )}

            {aufgabenWeg && weg === 'aufgabe'
                ? umhuellen(
                      <div className="space-y-4">
                          <div className="space-y-2">
                              <p className="text-sm font-medium">{beschriftung.plannableSlot}</p>
                              <div className="flex flex-col gap-2 sm:flex-row">
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
                              </div>
                          </div>

                          <div className="space-y-2">
                              <p className="text-sm font-medium">{beschriftung.plannablePick}</p>
                              <PlannableSearch
                                  search={plannable!.search}
                                  onPick={(kennung) => plannable!.onSchedule(kennung, zeitraum)}
                              />
                          </div>

                          {/*
                              Eine NEUE Aufgabe anzulegen gehoert nicht hierher:
                              Titel, Prioritaet und Projekt sind Begriffe des
                              Aufgabensystems. Der Zeitraum reist in der Adresse
                              mit, damit er beim Zurueckkommen nicht fehlt.
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
                  )
                : umhuellen(
                <EventForm
                    kinds={sichtbareArten}
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
