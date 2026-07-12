/**
 * The offline feedback queue (SCREENS S11).
 *
 * France-corridor dead zones are the normal condition, not an exception (PRD
 * risk #10). "Losing a golden-label tap to a dead zone is not acceptable" — so a
 * tap is written to disk BEFORE the network is attempted, and only removed once
 * the server has actually taken it.
 *
 * Two properties this file exists to guarantee:
 *
 *  1. Nothing is lost. The tap is durable before the request, not after it, so a
 *     browser killed mid-flight still has it on next open.
 *  2. Nothing is silently doubled. Each entry carries an id, and flushing is
 *     idempotent against interruption: an entry is dropped from the queue only
 *     on a definitive answer from the server.
 *
 * `occurred_at` travels with the entry because when you tapped is not when we
 * hear about it, and the server reasons about elapsed time.
 */

const STORAGE_KEY = 'feedback:queue:v1';

/** A dead zone is not infinite, and neither is localStorage. */
const MAX_ENTRIES = 200;

export interface QueuedFeedback {
    id: string;
    recommendation_id: string;
    event: string;
    metadata: Record<string, string | number | boolean>;
    occurred_at: string;
}

type Storage = Pick<globalThis.Storage, 'getItem' | 'setItem'>;

function storage(): Storage | null {
    try {
        return globalThis.localStorage ?? null;
    } catch {
        return null; // Safari private mode throws on access rather than returning null
    }
}

export function readQueue(store: Storage | null = storage()): QueuedFeedback[] {
    if (store === null) return [];

    try {
        const raw = store.getItem(STORAGE_KEY);
        const parsed: unknown = raw === null ? [] : JSON.parse(raw);

        return Array.isArray(parsed) ? (parsed as QueuedFeedback[]) : [];
    } catch {
        // A corrupt queue must not brick every future tap.
        return [];
    }
}

function writeQueue(entries: QueuedFeedback[], store: Storage | null = storage()): void {
    store?.setItem(STORAGE_KEY, JSON.stringify(entries));
}

export function enqueue(
    entry: Omit<QueuedFeedback, 'id' | 'occurred_at'> & { occurred_at?: string },
    store: Storage | null = storage(),
): QueuedFeedback {
    const queued: QueuedFeedback = {
        ...entry,
        id: `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`,
        occurred_at: entry.occurred_at ?? new Date().toISOString(),
    };

    // Drop the OLDEST on overflow, not the newest: the tap the user just made is
    // the one they are watching, and the one most likely to still matter.
    const entries = [...readQueue(store), queued].slice(-MAX_ENTRIES);
    writeQueue(entries, store);

    return queued;
}

export type SendResult = 'sent' | 'rejected' | 'unreachable';

/**
 * Drain the queue, oldest first.
 *
 * The three outcomes are deliberately distinct, and conflating them is how a
 * queue either loses data or wedges forever:
 *
 *   sent        the server took it        → drop it
 *   rejected    the server refused it     → drop it. A 4xx will be a 4xx forever;
 *                                           retrying it on every reconnect is an
 *                                           infinite loop that also blocks the
 *                                           entries behind it.
 *   unreachable we never got an answer    → KEEP it, and stop. Still offline.
 */
export async function flushQueue(
    send: (entry: QueuedFeedback) => Promise<SendResult>,
    store: Storage | null = storage(),
): Promise<{ sent: number; rejected: number; remaining: number }> {
    const entries = readQueue(store);
    let sent = 0;
    let rejected = 0;

    for (const entry of entries) {
        const result = await send(entry);

        if (result === 'unreachable') break; // the network is still gone — try again later

        if (result === 'sent') {
            sent++;
        } else {
            rejected++;
        }

        // Persist after EACH entry, not at the end: a tab closed mid-flush must not
        // re-send everything it already delivered.
        writeQueue(readQueue(store).slice(1), store);
    }

    return { sent, rejected, remaining: readQueue(store).length };
}
