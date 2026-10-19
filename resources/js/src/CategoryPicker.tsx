export type PickableCategory = { slug: string; label: string; colour?: string | null };

export type CategoryPickerProps = {
    /** Was diese Terminart anbietet — vom Produkt gereicht. */
    available: PickableCategory[];
    /** Was der Termin gerade trägt, als Slugs. */
    selected: string[];
    /**
     * Wird mit der NEUEN vollständigen Liste gerufen, nicht mit der Änderung.
     *
     * Eine Maske schickt den Zustand, den sie anzeigt. Wer stattdessen
     * „hinzugefügt"/„entfernt" schickt, zwingt den Empfänger zum
     * Zusammenrechnen — und zwei Rechnungen laufen auseinander.
     */
    onChange: (slugs: string[]) => void;
    disabled?: boolean;
    className?: string;
};

/**
 * Die Kategorien eines Termins wählen.
 *
 * ## Warum das ein eigener Baustein ist
 *
 * Er steckte zuerst in {@link EventDetail}. Das reicht für CRM und Verwaltung,
 * die diese Ansicht benutzen — der Manager hat eine eigene, gewachsene
 * Detailseite mit 604 Zeilen, und genau dort liegen die Termine, um die es
 * geht: Gemessen am 19.10.2026 sind 483 von 1211 Terminen Zeitblöcke an einer
 * Aufgabe, und keiner konnte eine Kategorie tragen.
 *
 * Die Wahl zweimal zu bauen hieße, die Umschaltlogik zweimal zu bauen — und
 * sie hat genau eine Stelle, an der man sich vertut: ob gemeldet wird, was
 * sich geändert hat, oder was jetzt gilt.
 *
 * ## Knöpfe und keine Kästchen
 *
 * `aria-pressed` statt `checkbox`: Es ist kein Formular, das abgeschickt wird
 * — jeder Klick ist die Änderung. Ein Kästchen verspricht ein „Speichern",
 * das es hier nicht gibt.
 */
export function CategoryPicker({ available, selected, onChange, disabled = false, className = '' }: CategoryPickerProps) {
    // Kein leerer Auswahlbereich: Er ist schlimmer als keiner, weil er eine
    // Wahl verspricht, die es nicht gibt.
    if (available.length === 0) {
        return null;
    }

    return (
        <div className={`flex flex-wrap gap-1.5 ${className}`} data-testid="category-picker">
            {available.map((kategorie) => {
                const gewaehlt = selected.includes(kategorie.slug);

                return (
                    <button
                        key={kategorie.slug}
                        type="button"
                        aria-pressed={gewaehlt}
                        disabled={disabled}
                        onClick={() =>
                            onChange(
                                gewaehlt
                                    ? selected.filter((slug) => slug !== kategorie.slug)
                                    : [...selected, kategorie.slug],
                            )
                        }
                        className={`rounded-md border px-1.5 py-0.5 text-xs transition disabled:opacity-50 ${
                            gewaehlt
                                ? 'border-primary bg-primary/10 font-medium'
                                : 'text-muted-foreground hover:border-foreground/40'
                        }`}
                    >
                        {kategorie.colour !== null && kategorie.colour !== undefined && kategorie.colour !== '' && (
                            <span
                                aria-hidden="true"
                                className="mr-1 inline-block h-2 w-2 rounded-full align-middle"
                                style={{ backgroundColor: kategorie.colour }}
                            />
                        )}
                        {kategorie.label}
                    </button>
                );
            })}
        </div>
    );
}
