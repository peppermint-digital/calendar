/**
 * Was eine Terminart ueber sich sagt.
 *
 * Dieselbe Form fuer eigene Arten und fuer die eines anderen Systems: Der
 * Dialog kennt den Unterschied nicht, und das ist der Sinn — wer aus seinem
 * Kalender heraus einen Termin im Arbeitskalender anlegt, bekommt dessen
 * Felder, ohne dass hier jemand das andere Produkt kennt.
 */
export type EventKind = {
    key: string;
    label: string;
    /** Felder, ohne die diese Art nicht gespeichert werden kann (aus `rules()`). */
    requires?: string[];
    /** Felder, die diese Art nie tragen darf (aus `forbiddenAttributes()`). */
    forbids?: string[];
    /** Fuehrt diese Art Kategorien? */
    usesCategories?: boolean;
    /**
     * Darf man diese Art von Hand waehlen?
     *
     * Nicht jede Art ist etwas, das man *aussucht*: Ein geplanter Block
     * entsteht durchs Hereinziehen einer Aufgabe, ein Urlaubseintrag spiegelt
     * einen genehmigten Antrag. Solche Arten gehoeren in die Anzeige, aber
     * nicht in die Auswahl — das Produkt siebt sie aus, bevor es die Liste
     * hereinreicht.
     */
    creatable?: boolean;
};

/** Die Wochentagskuerzel, wie RFC 5545 und das PHP-Paket sie schreiben. */
export const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'] as const;

export type Weekday = (typeof WEEKDAYS)[number];

/** Die Frequenzen aus `Peppermint\Calendar\Enums\Frequency`. */
export type Frequency = 'daily' | 'weekly' | 'biweekly' | 'monthly';

/**
 * Eine Serie in genau der Form, die `RecurrenceRule::fromArray()` entgegennimmt.
 *
 * Bewusst dieselben Namen wie drueben: Ein Formular, das `repeat_until` heisst
 * und auf `until` gemappt werden muss, hat eine Uebersetzungsschicht, die
 * irgendwann auseinanderlaeuft.
 */
export type RecurrenceDraft = {
    frequency: Frequency;
    byDay: Weekday[];
    /** Leer heisst: wie der Starttag. Nur bei `monthly` von Belang. */
    byMonthDay: string;
    /** `YYYY-MM-DD` — das Ende der Serie, nicht Teil der Regel selbst. */
    until: string;
};

export const emptyRecurrence = (): RecurrenceDraft => ({
    frequency: 'weekly',
    byDay: [],
    byMonthDay: '',
    until: '',
});

export type EventCategory = {
    /** Fehlt bei Listen, die ueber die Beschriftung gefuehrt werden. */
    id?: string | number;
    label: string;
    colour?: string | null;
};

/**
 * Wie die Kategorienliste gefuehrt wird — dieselben Werte wie
 * `Peppermint\Calendar\Enums\CategoryMode`.
 *
 * `closed` heisst: eine gepflegte Liste, an der niemand im Vorbeigehen etwas
 * ergaenzt. Dann ist eine Auswahl richtig und ein Textfeld mit Vorschlaegen
 * falsch — es liesse etwas eintippen, was der Server anschliessend ablehnt.
 */
export type CategoryMode = 'closed' | 'personal' | 'open';

/** Die Felder, die jeder Kalender teilt. Alles Weitere reicht das Produkt hinein. */
export type EventDraft = {
    kind: string;
    title: string;
    /** Kalendertag, `YYYY-MM-DD`. */
    date: string;
    /**
     * Letzter Kalendertag, wenn der Termin ueber mehrere geht. Leer heisst:
     * derselbe Tag. Nur sichtbar, wo das Produkt Mehrtagestermine kennt.
     */
    endDate: string;
    /** `HH:MM`, leer bei ganztaegig. */
    start: string;
    end: string;
    allDay: boolean;
    location: string;
    description: string;
    meetingUrl: string;
    /** Die Beschriftung — bei offenen und persoenlichen Listen. */
    category: string;
    /** Die Kennung — bei einer gepflegten Liste (`closed`). */
    categoryId: string;
    /** null heisst: einmalig. Arten, die keine Serien fuehren, lassen es dabei. */
    recurrence: RecurrenceDraft | null;
};

export const emptyDraft = (kind = ''): EventDraft => ({
    kind,
    title: '',
    date: '',
    endDate: '',
    start: '',
    end: '',
    allDay: false,
    location: '',
    description: '',
    meetingUrl: '',
    category: '',
    categoryId: '',
    recurrence: null,
});

/**
 * Die Feldnamen, wie das PHP-Paket sie kennt — `requires` und `forbids` reden
 * in dieser Sprache, nicht in der des Formulars.
 */
export const FIELD = {
    title: 'title',
    date: 'starts_at',
    endDate: 'ends_at',
    start: 'starts_at',
    end: 'ends_at',
    allDay: 'all_day',
    location: 'location',
    description: 'description',
    meetingUrl: 'meeting_url',
    category: 'category',
    categoryId: 'category_id',
    recurrence: 'recurrence_rules',
} as const;

export type FieldKey = keyof typeof FIELD;
