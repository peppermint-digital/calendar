import type { ReactNode } from 'react';
import type { EventCategory, EventDraft, EventKind } from './types';
import { useEventForm } from './useEventForm';

export type EventFormLabels = {
    kind: string;
    title: string;
    date: string;
    from: string;
    to: string;
    allDay: string;
    location: string;
    description: string;
    meetingUrl: string;
    category: string;
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
    allDay: 'Ganztägig',
    location: 'Ort',
    description: 'Beschreibung',
    meetingUrl: 'Meeting-Link',
    category: 'Kategorie',
    submit: 'Speichern',
    cancel: 'Abbrechen',
    missing: 'Diese Terminart verlangt noch:',
};

export type EventFormProps = {
    kinds: EventKind[];
    categories?: EventCategory[];
    value: EventDraft;
    onChange: (draft: EventDraft) => void;
    onSubmit: () => void;
    onCancel?: () => void;
    /** Felder, die nur dieses Produkt kennt — Projekt, Kunde, Abrechnung. */
    extraFields?: ReactNode;
    submitting?: boolean;
    error?: string | null;
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
    value,
    onChange,
    onSubmit,
    onCancel,
    extraFields,
    submitting = false,
    error = null,
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
                </div>

                {!value.allDay && (
                    <>
                        <div className="w-28">
                            <label className={label} htmlFor="calendar-start">{text.from}</label>
                            <input
                                id="calendar-start"
                                type="time"
                                className={field}
                                value={value.start}
                                onChange={(event) => set('start', event.target.value)}
                            />
                        </div>
                        <div className="w-28">
                            <label className={label} htmlFor="calendar-end">{text.to}</label>
                            <input
                                id="calendar-end"
                                type="time"
                                className={field}
                                value={value.end}
                                onChange={(event) => set('end', event.target.value)}
                            />
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
                </div>
            )}

            {rules.usesCategories && categories.length > 0 && (
                <div>
                    <label className={label} htmlFor="calendar-category">{text.category}</label>
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
                </div>
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
