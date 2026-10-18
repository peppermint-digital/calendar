import { useCallback, useEffect, useState } from 'react';

import { Input } from './ui/input';

/** Eine Zeile der Liste, so wie `CategoryAdminController` sie liefert. */
export type AdminCategory = {
    id: number;
    slug: string;
    label: string;
    colour: string | null;
    sort_order: number;
    is_active: boolean;
    kind: string | null;
    /** Wie viele Termine sie tragen — Untergrenze, siehe Controller. */
    in_use: number;
};

export type CategoryAdminLabels = {
    title: string;
    name: string;
    colour: string;
    order: string;
    kind: string;
    everyKind: string;
    active: string;
    add: string;
    save: string;
    remove: string;
    empty: string;
    slugHint: string;
    inUse: (anzahl: number) => string;
};

export const defaultCategoryAdminLabels: CategoryAdminLabels = {
    title: 'Kategorien',
    name: 'Name',
    colour: 'Farbe',
    order: 'Reihenfolge',
    kind: 'Terminart',
    everyKind: 'jede Art',
    active: 'aktiv',
    add: 'Kategorie hinzufügen',
    save: 'Speichern',
    remove: 'Löschen',
    empty: 'Noch keine Kategorie angelegt.',
    slugHint: 'Der Slug entsteht aus dem Namen und bleibt danach fest — er ist der Schlüssel, auf den sich die Systeme einigen.',
    inUse: (anzahl) => `${anzahl} Termine`,
};

export type CategoryAdminProps = {
    /**
     * Wo die Liste liegt. Vorgabe passt zu
     * `CalendarRoutes::categories()` unter `api/calendar`.
     *
     * Überschreibbar, weil das Produkt die Adressen legt — nicht das Paket.
     */
    endpoint?: string;
    labels?: Partial<CategoryAdminLabels>;
    /** Wird bei jedem Fehlschlag mit der Meldung des Servers gerufen. */
    onError?: (meldung: string) => void;
};

/**
 * Die Pflegemaske für die gemeinsame Kategorienliste.
 *
 * ## Warum sie im Paket liegt
 *
 * Die Liste ist in vier Systemen dieselbe. Vier Masken dafür wären vier
 * Gelegenheiten, die Regeln verschieden zu bauen — und die Regeln sind hier
 * der Punkt: Der Slug bleibt beim Umbenennen, gelöscht wird nur, was nichts
 * trägt, und eine deaktivierte Kategorie muss in ihrer eigenen Verwaltung
 * sichtbar bleiben.
 *
 * Eingebunden wird sie dort, wo sie hingehört: im Einstellungsmenü des AI
 * Brain, wenn es das Brain gibt, und im jeweiligen Produkt, wenn nicht
 * (#8178).
 *
 * ## Warum Löschen hier unbequem ist
 *
 * An einer Kategorie hängen Termine. Eine gelöschte nimmt deren Einordnung
 * mit, und das fällt nicht beim Löschen auf, sondern Wochen später in der
 * Auswertung — als fehlende Stunden, die niemand erklären kann. Deshalb zeigt
 * jede Zeile, wie viele Termine sie trägt, und der Löschknopf verschwindet,
 * sobald es mehr als null sind. Der Server verweigert es ohnehin (409); die
 * Maske nimmt den Griff weg, bevor jemand danach greift.
 */
export function CategoryAdmin({ endpoint = '/api/calendar/categories', labels, onError }: CategoryAdminProps) {
    const t = { ...defaultCategoryAdminLabels, ...labels };

    const [kategorien, setKategorien] = useState<AdminCategory[]>([]);
    const [arten, setArten] = useState<string[]>([]);
    const [laedt, setLaedt] = useState(true);
    const [neuerName, setNeuerName] = useState('');

    const melden = useCallback(
        async (antwort: Response) => {
            let meldung = `${antwort.status}`;
            try {
                const rumpf = await antwort.json();
                meldung = rumpf?.message ?? meldung;
            } catch {
                // Kein JSON — dann bleibt der Status die Meldung.
            }
            onError?.(meldung);
        },
        [onError],
    );

    const laden = useCallback(async () => {
        setLaedt(true);
        try {
            const antwort = await fetch(endpoint, { headers: { Accept: 'application/json' } });
            if (!antwort.ok) {
                await melden(antwort);
                return;
            }
            const rumpf = await antwort.json();
            setKategorien(rumpf.categories ?? []);
            setArten(rumpf.kinds ?? []);
        } finally {
            setLaedt(false);
        }
    }, [endpoint, melden]);

    useEffect(() => {
        void laden();
    }, [laden]);

    /**
     * Das CSRF-Zeichen aus dem Kopf der Seite.
     *
     * Laravel legt es als `<meta name="csrf-token">` ab. Ohne es antwortet
     * jede schreibende Anfrage mit 419, und das sieht in der Maske aus wie
     * „geht nicht" statt „Zeichen fehlt".
     */
    const schreibkopf = (): Record<string, string> => {
        const zeichen = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

        return {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(zeichen ? { 'X-CSRF-TOKEN': zeichen } : {}),
        };
    };

    const anlegen = async () => {
        const name = neuerName.trim();
        if (name === '') return;

        const antwort = await fetch(endpoint, {
            method: 'POST',
            headers: schreibkopf(),
            body: JSON.stringify({ label: name }),
        });

        if (!antwort.ok) {
            await melden(antwort);
            return;
        }

        setNeuerName('');
        await laden();
    };

    const sichern = async (kategorie: AdminCategory, änderung: Partial<AdminCategory>) => {
        const antwort = await fetch(`${endpoint}/${kategorie.id}`, {
            method: 'PATCH',
            headers: schreibkopf(),
            body: JSON.stringify(änderung),
        });

        if (!antwort.ok) {
            await melden(antwort);
            return;
        }

        await laden();
    };

    const entfernen = async (kategorie: AdminCategory) => {
        const antwort = await fetch(`${endpoint}/${kategorie.id}`, {
            method: 'DELETE',
            headers: schreibkopf(),
        });

        if (!antwort.ok) {
            await melden(antwort);
            return;
        }

        await laden();
    };

    return (
        <div className="space-y-4" data-testid="category-admin">
            <div>
                <h2 className="text-lg font-semibold">{t.title}</h2>
                <p className="text-muted-foreground text-sm">{t.slugHint}</p>
            </div>

            {laedt ? null : kategorien.length === 0 ? (
                <p className="text-muted-foreground text-sm">{t.empty}</p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground text-left text-xs uppercase">
                            <tr>
                                <th className="py-2 pr-3">{t.colour}</th>
                                <th className="py-2 pr-3">{t.name}</th>
                                <th className="py-2 pr-3">{t.kind}</th>
                                <th className="py-2 pr-3 text-right">{t.order}</th>
                                <th className="py-2 pr-3">{t.active}</th>
                                <th className="py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {kategorien.map((kategorie) => (
                                <tr key={kategorie.id} className="border-t" data-slug={kategorie.slug}>
                                    <td className="py-2 pr-3">
                                        <input
                                            type="color"
                                            aria-label={`${t.colour}: ${kategorie.label}`}
                                            value={kategorie.colour ?? '#64748b'}
                                            onChange={(e) => void sichern(kategorie, { colour: e.target.value })}
                                            className="h-7 w-10 cursor-pointer rounded border"
                                        />
                                    </td>
                                    <td className="py-2 pr-3">
                                        <Input
                                            aria-label={`${t.name}: ${kategorie.label}`}
                                            defaultValue={kategorie.label}
                                            onBlur={(e) => {
                                                const wert = e.target.value.trim();
                                                if (wert !== '' && wert !== kategorie.label) {
                                                    void sichern(kategorie, { label: wert });
                                                }
                                            }}
                                        />
                                        {/* Der Slug steht sichtbar daneben, damit erkennbar
                                            ist, dass er beim Umbenennen NICHT mitwandert. */}
                                        <span className="text-muted-foreground mt-1 block font-mono text-xs">
                                            {kategorie.slug}
                                        </span>
                                    </td>
                                    <td className="py-2 pr-3">
                                        <select
                                            aria-label={`${t.kind}: ${kategorie.label}`}
                                            value={kategorie.kind ?? ''}
                                            onChange={(e) =>
                                                void sichern(kategorie, { kind: e.target.value === '' ? null : e.target.value })
                                            }
                                            className="bg-background rounded border px-2 py-1 text-sm"
                                        >
                                            <option value="">{t.everyKind}</option>
                                            {arten.map((art) => (
                                                <option key={art} value={art}>
                                                    {art}
                                                </option>
                                            ))}
                                        </select>
                                    </td>
                                    <td className="py-2 pr-3 text-right tabular-nums">
                                        <Input
                                            aria-label={`${t.order}: ${kategorie.label}`}
                                            type="number"
                                            defaultValue={kategorie.sort_order}
                                            onBlur={(e) => {
                                                const wert = Number.parseInt(e.target.value, 10);
                                                if (!Number.isNaN(wert) && wert !== kategorie.sort_order) {
                                                    void sichern(kategorie, { sort_order: wert });
                                                }
                                            }}
                                            className="w-20 text-right"
                                        />
                                    </td>
                                    <td className="py-2 pr-3">
                                        <input
                                            type="checkbox"
                                            aria-label={`${t.active}: ${kategorie.label}`}
                                            checked={kategorie.is_active}
                                            onChange={(e) => void sichern(kategorie, { is_active: e.target.checked })}
                                            className="h-4 w-4 cursor-pointer"
                                        />
                                    </td>
                                    <td className="py-2 text-right">
                                        {kategorie.in_use > 0 ? (
                                            /* Kein Löschknopf, sobald Termine daran hängen —
                                               der Server verweigert es ohnehin, aber der Griff
                                               soll gar nicht erst da sein. */
                                            <span className="text-muted-foreground text-xs">
                                                {t.inUse(kategorie.in_use)}
                                            </span>
                                        ) : (
                                            <button
                                                type="button"
                                                onClick={() => void entfernen(kategorie)}
                                                className="text-destructive text-xs hover:underline"
                                            >
                                                {t.remove}
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <div className="flex gap-2">
                <Input
                    aria-label={t.add}
                    placeholder={t.add}
                    value={neuerName}
                    onChange={(e) => setNeuerName(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            void anlegen();
                        }
                    }}
                />
                <button
                    type="button"
                    onClick={() => void anlegen()}
                    disabled={neuerName.trim() === ''}
                    className="bg-primary text-primary-foreground rounded px-3 py-2 text-sm disabled:opacity-50"
                >
                    {t.add}
                </button>
            </div>
        </div>
    );
}
