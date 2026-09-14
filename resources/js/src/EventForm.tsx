import type { ReactNode } from 'react';
import { weekdayOf } from './rules';
import { emptyRecurrence, WEEKDAYS, type CategoryMode, type EventCategory, type EventDraft, type EventKind, type FieldKey, type Frequency, type RecurrenceDraft } from './types';
import { useEventForm } from './useEventForm';

export type EventFormLabels = {
    kind: string;
    title: string;
    date: string;
    from: string;
    to: string;
    endDate: string;
    allDay: string;
    location: string;
    description: string;
    meetingUrl: string;
    category: string;
    recurring: string;
    frequency: string;
    weekdays: string;
    monthDay: string;
    monthDayHint: string;
    until: string;
    frequencies: Record<Frequency, string>;
    submit: string;
    cancel: string;
    missing: string;
};

const DEFAULTS: EventFormLabels = {
    kind: 'Terminart',
    title: 'Titel',
    date: 'Datum',
    from: 'Von',
    to: 'Bis',
    endDate: 'Enddatum',
    allDay: 'Ganztägig',
    location: 'Ort',
    description: 'Beschreibung',
    meetingUrl: 'Meeting-Link',
    category: 'Kategorie',
    recurring: 'Wiederholt sich',
    frequency: 'Frequenz',
    weekdays: 'An diesen Tagen',
    monthDay: 'Tag im Monat',
    monthDayHint: 'wie Start',
    until: 'Wiederholen bis',
    frequencies: {
        daily: 'täglich',
        weekly: 'wöchentlich',
        biweekly: 'alle zwei Wochen',
        monthly: 'monatlich',
    },
    submit: 'Speichern',
    cancel: 'Abbrechen',
    missing: 'Diese Terminart verlangt noch:',
};

/**
 * Womit ein Feld beanstandet wird — die Schluessel des Formulars, nicht die des
 * Servers. Die Teile der Serie einzeln, weil ein Server sie einzeln ablehnt:
 * „Wiederholen bis fehlt" gehoert an das Datum und nicht ueber den ganzen Block.
 *
 * `kind` steht hier, aber nicht in FIELD: Die Terminart kann von keiner Art
 * verlangt oder verboten werden — sie IST die Art. Beanstandet werden kann sie
 * trotzdem.
 */
export type EventFormErrorKey = FieldKey | 'kind' | `recurrence.${keyof RecurrenceDraft}`;

export type EventFormErrors = Partial<Record<EventFormErrorKey, string>>;

/** Die Beanstandung unter dem Feld, das sie meint. */
function FieldError({ message }: { message?: string }) {
    return message ? <p className="text-destructive mt-1 text-xs">{message}</p> : null;
}

export type EventFormProps = {
    kinds: EventKind[];
    categories?: EventCategory[];
    /**
     * Wie die Kategorienliste gefuehrt wird. `closed` gibt eine Auswahl ueber
     * `categoryId`, alles andere ein Textfeld mit Vorschlaegen ueber `category`.
     */
    categoryMode?: CategoryMode;
    /**
     * Kennt dieser Kalender Termine ueber mehrere Tage?
     *
     * Eine Eigenschaft des Produkts, nicht der Terminart: Ein persoenlicher
     * Kalender ohne Mehrtagestermine soll kein Enddatum zeigen, das immer dem
     * Starttag entspricht.
     */
    multiDay?: boolean;
    /**
     * Eigenes Bedienelement fuer Uhrzeiten statt `<input type="time">`.
     *
     * Der Manager waehlt Stunde und Minute ueber zwei Listen mit Fuenf-Minuten-
     * Raster. Das ist kein Zierrat, sondern eine Entscheidung mit Begruendung —
     * und die Reihenfolge der Felder zu teilen heisst nicht, sie aufzugeben.
     */
    renderTime?: (props: { id: string; value: string; onChange: (value: string) => void }) => ReactNode;
    value: EventDraft;
    onChange: (draft: EventDraft) => void;
    onSubmit: () => void;
    onCancel?: () => void;
    /** Felder, die nur dieses Produkt kennt — Projekt, Kunde, Abrechnung. */
    extraFields?: ReactNode;
    submitting?: boolean;
    error?: string | null;
    /**
     * Beanstandungen pro Feld, wie sie vom Server zurueckkommen.
     *
     * `error` allein reicht nicht: Wer eine Maske mit zwoelf Feldern absendet
     * und eine einzelne Zeile darueber gesetzt bekommt, sucht das gemeinte Feld
     * selbst. Das Produkt uebersetzt seine Server-Schluessel auf die des
     * Formulars — genauso, wie es die Nutzlast uebersetzt.
     */
    fieldErrors?: EventFormErrors;
    labels?: Partial<EventFormLabels>;
};

/**
 * Der Termin-Dialog, der sich aus den Terminarten baut.
 *
 * Welche Felder erscheinen, entscheidet die gewaehlte Art — nicht dieses
 * Formular. Damit zeigt derselbe Dialog in einem persoenlichen Kalender vier
 * Felder und in einem Arbeitskalender zwoelf, ohne dass es ihn zweimal gibt.
 *
 * Bewusst ohne UI-Bibliothek: schlichte Elemente mit Tailwind-Klassen. Eine
 * Abhaengigkeit auf eine Komponentensammlung zwaenge sie jedem Verbraucher auf,
 * und zwei Sammlungen im selben Baum vertragen sich selten.
 */
export function EventForm({
    kinds,
    categories = [],
    categoryMode = 'personal',
    multiDay = false,
    renderTime,
    value,
    onChange,
    onSubmit,
    onCancel,
    extraFields,
    submitting = false,
    error = null,
    fieldErrors,
    labels,
}: EventFormProps) {
    const text = { ...DEFAULTS, ...labels };
    const rules = useEventForm(kinds, value);
    const set = <K extends keyof EventDraft>(field: K, next: EventDraft[K]) =>
        onChange({ ...value, [field]: next });

    const field = 'w-full rounded-md border border-input bg-background px-3 py-2 text-sm';
    const label = 'mb-1 block text-xs font-medium text-muted-foreground';

    return (
        <form
            className="flex flex-col gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                onSubmit();
            }}
        >
            {/* Eine Auswahl mit einem einzigen Eintrag stellt eine Frage, die keine ist. */}
            {kinds.length > 1 && (
                <div>
                    <label className={label} htmlFor="calendar-kind">{text.kind}</label>
                    <select
                        id="calendar-kind"
                        className={field}
                        value={rules.kind?.key ?? ''}
                        onChange={(event) => set('kind', event.target.value)}
                    >
                        {kinds.map((kind) => (
                            <option key={kind.key} value={kind.key}>{kind.label}</option>
                        ))}
                    </select>
                    <FieldError message={fieldErrors?.kind} />
                </div>
            )}

            <div>
                <label className={label} htmlFor="calendar-title">{text.title}</label>
                <input
                    id="calendar-title"
                    className={field}
                    value={value.title}
                    onChange={(event) => set('title', event.target.value)}
                    required
                />
                <FieldError message={fieldErrors?.title} />
            </div>

            <div className="flex flex-wrap gap-2">
                <div className="min-w-36 flex-1">
                    <label className={label} htmlFor="calendar-date">{text.date}</label>
                    <input
                        id="calendar-date"
                        type="date"
                        className={field}
                        value={value.date}
                        onChange={(event) => set('date', event.target.value)}
                        required
                    />
                    <FieldError message={fieldErrors?.date} />
                </div>

                {multiDay && (
                    <div className="min-w-36 flex-1">
                        <label className={label} htmlFor="calendar-end-date">{text.endDate}</label>
                        <input
                            id="calendar-end-date"
                            type="date"
                            className={field}
                            min={value.date || undefined}
                            value={value.endDate}
                            onChange={(event) => set('endDate', event.target.value)}
                        />
                        <FieldError message={fieldErrors?.endDate} />
                    </div>
                )}

                {!value.allDay && (
                    <>
                        <div className="w-28">
                            <label className={label} htmlFor="calendar-start">{text.from}</label>
                            {renderTime ? (
                                renderTime({
                                    id: 'calendar-start',
                                    value: value.start,
                                    onChange: (next) => set('start', next),
                                })
                            ) : (
                                <input
                                    id="calendar-start"
                                    type="time"
                                    className={field}
                                    value={value.start}
                                    onChange={(event) => set('start', event.target.value)}
                                />
                            )}
                            <FieldError message={fieldErrors?.start} />
                        </div>
                        <div className="w-28">
                            <label className={label} htmlFor="calendar-end">{text.to}</label>
                            {renderTime ? (
                                renderTime({
                                    id: 'calendar-end',
                                    value: value.end,
                                    onChange: (next) => set('end', next),
                                })
                            ) : (
                                <input
                                    id="calendar-end"
                                    type="time"
                                    className={field}
                                    value={value.end}
                                    onChange={(event) => set('end', event.target.value)}
                                />
                            )}
                            <FieldError message={fieldErrors?.end} />
                        </div>
                    </>
                )}
            </div>

            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={value.allDay}
                    onChange={(event) => set('allDay', event.target.checked)}
                />
                {text.allDay}
            </label>

            {rules.shows('location') && (
                <div>
                    <label className={label} htmlFor="calendar-location">
                        {text.location}{rules.needs('location') ? ' *' : ''}
                    </label>
                    <input
                        id="calendar-location"
                        className={field}
                        value={value.location}
                        onChange={(event) => set('location', event.target.value)}
                    />
                    <FieldError message={fieldErrors?.location} />
                </div>
            )}

            {rules.shows('meetingUrl') && (
                <div>
                    <label className={label} htmlFor="calendar-meeting-url">
                        {text.meetingUrl}{rules.needs('meetingUrl') ? ' *' : ''}
                    </label>
                    <input
                        id="calendar-meeting-url"
                        type="url"
                        placeholder="https://…"
                        className={field}
                        value={value.meetingUrl}
                        onChange={(event) => set('meetingUrl', event.target.value)}
                    />
                    <FieldError message={fieldErrors?.meetingUrl} />
                </div>
            )}

            {rules.usesCategories && categories.length > 0 && (
                <div>
                    <label className={label} htmlFor="calendar-category">{text.category}</label>

                    {categoryMode === 'closed' ? (
                        /* Eine gepflegte Liste: Tippen liesse etwas eingeben, was der
                           Server anschliessend ablehnt. Also eine Auswahl. */
                        <select
                            id="calendar-category"
                            className={field}
                            value={value.categoryId}
                            onChange={(event) => set('categoryId', event.target.value)}
                        >
                            <option value="">—</option>
                            {categories.map((category) => (
                                <option key={String(category.id ?? category.label)} value={String(category.id ?? '')}>
                                    {category.label}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <>
                            <input
                                id="calendar-category"
                                className={field}
                                list="calendar-category-options"
                                value={value.category}
                                onChange={(event) => set('category', event.target.value)}
                            />
                            {/* Vorschlaege aus der gepflegten Liste, Tippen bleibt erlaubt —
                                ob eine neue entstehen darf, entscheidet der Server. */}
                            <datalist id="calendar-category-options">
                                {categories.map((category) => (
                                    <option key={category.label} value={category.label} />
                                ))}
                            </datalist>
                        </>
                    )}

                    <FieldError message={fieldErrors?.category ?? fieldErrors?.categoryId} />
                </div>
            )}

            {rules.shows('description') && (
                <div>
                    <label className={label} htmlFor="calendar-description">{text.description}</label>
                    <textarea
                        id="calendar-description"
                        className={field}
                        rows={3}
                        value={value.description}
                        onChange={(event) => set('description', event.target.value)}
                    />
                    <FieldError message={fieldErrors?.description} />
                </div>
            )}

            {rules.shows('recurrence') && (
                <RecurrenceFields
                    value={value.recurrence}
                    onChange={(next) => set('recurrence', next)}
                    startDate={value.date}
                    errors={fieldErrors}
                    text={text}
                    field={field}
                    label={label}
                />
            )}

            {extraFields}

            {/* Was die Art verlangt und noch fehlt, steht hier — nicht erst in der
                Antwort des Servers. Eine Ablehnung nach dem Absenden ist eine
                vermeidbare Enttaeuschung. */}
            {rules.missing.length > 0 && (
                <p className="text-xs text-amber-600">
                    {text.missing} {rules.missing.join(', ')}
                </p>
            )}

            {error !== null && <p className="text-destructive text-sm">{error}</p>}

            <div className="flex gap-2">
                <button
                    type="submit"
                    className="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground disabled:opacity-50"
                    disabled={submitting || !rules.complete}
                >
                    {text.submit}
                </button>
                {onCancel && (
                    <button type="button" className="rounded-md border px-3 py-2 text-sm" onClick={onCancel}>
                        {text.cancel}
                    </button>
                )}
            </div>
        </form>
    );
}


/**
 * Die Serie: ein Schalter, und dahinter genau die Felder, die die gewaehlte
 * Frequenz braucht.
 *
 * Beim Einschalten stehen Wochentag und Enddatum schon da, aus dem Starttag
 * abgeleitet. Eine leere Maske mit zwei Pflichtfeldern waere die gleiche
 * Auskunft, nur muesste der Mensch sie abtippen.
 */
function RecurrenceFields({
    value,
    onChange,
    startDate,
    errors,
    text,
    field,
    label,
}: {
    value: RecurrenceDraft | null;
    onChange: (next: RecurrenceDraft | null) => void;
    startDate: string;
    errors?: EventFormErrors;
    text: EventFormLabels;
    field: string;
    label: string;
}) {
    const set = <K extends keyof RecurrenceDraft>(key: K, next: RecurrenceDraft[K]) =>
        value && onChange({ ...value, [key]: next });

    const start = () => {
        const day = weekdayOf(startDate);
        const until = startDate === '' ? '' : `${Number(startDate.slice(0, 4)) + 1}${startDate.slice(4)}`;

        onChange({ ...emptyRecurrence(), byDay: day ? [day] : [], until });
    };

    return (
        <div className="flex flex-col gap-3 rounded-md border p-3">
            <label className="flex items-center gap-2 text-sm">
                <input
                    type="checkbox"
                    checked={value !== null}
                    onChange={(event) => (event.target.checked ? start() : onChange(null))}
                />
                {text.recurring}
            </label>

            {value !== null && (
                <div className="flex flex-wrap items-end gap-2">
                    <div className="w-44">
                        <label className={label} htmlFor="calendar-frequency">{text.frequency}</label>
                        <select
                            id="calendar-frequency"
                            className={field}
                            value={value.frequency}
                            onChange={(event) => set('frequency', event.target.value as Frequency)}
                        >
                            {(Object.keys(text.frequencies) as Frequency[]).map((frequency) => (
                                <option key={frequency} value={frequency}>{text.frequencies[frequency]}</option>
                            ))}
                        </select>
                        <FieldError message={errors?.['recurrence.frequency']} />
                    </div>

                    <div className="w-40">
                        <label className={label} htmlFor="calendar-until">{text.until} *</label>
                        <input
                            id="calendar-until"
                            type="date"
                            className={field}
                            value={value.until}
                            onChange={(event) => set('until', event.target.value)}
                        />
                        <FieldError message={errors?.['recurrence.until']} />
                    </div>

                    {value.frequency === 'monthly' && (
                        <div className="w-32">
                            <label className={label} htmlFor="calendar-month-day">{text.monthDay}</label>
                            <input
                                id="calendar-month-day"
                                type="number"
                                min={1}
                                max={31}
                                className={field}
                                placeholder={text.monthDayHint}
                                value={value.byMonthDay}
                                onChange={(event) => set('byMonthDay', event.target.value)}
                            />
                            <FieldError message={errors?.['recurrence.byMonthDay']} />
                        </div>
                    )}

                    {(value.frequency === 'weekly' || value.frequency === 'biweekly') && (
                        <div className="w-full">
                            <span className={label}>{text.weekdays} *</span>
                            <div className="flex flex-wrap gap-1">
                                {WEEKDAYS.map((day) => (
                                    <button
                                        key={day}
                                        type="button"
                                        aria-pressed={value.byDay.includes(day)}
                                        className={
                                            'rounded-md border px-2 py-1 text-xs ' +
                                            (value.byDay.includes(day)
                                                ? 'bg-primary text-primary-foreground border-primary'
                                                : 'bg-background')
                                        }
                                        onClick={() =>
                                            set(
                                                'byDay',
                                                value.byDay.includes(day)
                                                    ? value.byDay.filter((entry) => entry !== day)
                                                    : [...value.byDay, day],
                                            )
                                        }
                                    >
                                        {day}
                                    </button>
                                ))}
                            </div>
                            <FieldError message={errors?.['recurrence.byDay']} />
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
