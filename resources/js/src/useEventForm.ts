import { useMemo } from 'react';
import { eventFormRules } from './rules';
import type { EventDraft, EventKind } from './types';

/** {@link eventFormRules}, gemerkt zwischen zwei Darstellungen. */
export function useEventForm(kinds: EventKind[], draft: EventDraft) {
    return useMemo(() => eventFormRules(kinds, draft), [kinds, draft]);
}
