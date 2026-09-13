<?php

namespace Peppermint\Calendar\Enums;

/**
 * Wie weit darf die Kategorienliste wachsen?
 *
 * Die Frage hat keine allgemeingueltige Antwort: In einem Unternehmen ist eine
 * gepflegte Liste der Punkt, in einem persoenlichen Kalender waere sie eine
 * Schikane. Deshalb drei Stufen statt eines Schalters.
 */
enum CategoryMode: string
{
    /** Nur die verwaltete Liste. Niemand ergaenzt sie im Vorbeigehen. */
    case Closed = 'closed';

    /**
     * Jeder ergaenzt fuer sich. Die gemeinsame Liste bleibt kuratiert, und eine
     * frei getippte Kategorie landet nie ungefragt bei allen. Ein Administrator
     * kann sie hochstufen — das ist dann eine bewusste Handlung.
     */
    case Personal = 'personal';

    /** Jeder ergaenzt fuer alle. Fuer kleine Teams und eigene Installationen. */
    case Open = 'open';
}
