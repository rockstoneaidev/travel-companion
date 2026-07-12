import { router } from '@inertiajs/react';
import { enqueue, flushQueue, type QueuedFeedback, type SendResult } from './offline-queue';

/**
 * Sending feedback, dead zone or not (SCREENS S11).
 *
 * Every tap is written to the queue FIRST and sent second. That ordering is the
 * whole guarantee: a tap that is sent-then-stored is a tap you lose if the tab
 * dies mid-request, and the golden "I was here" label is the single most valuable
 * event in the learning loop.
 */

export type FeedbackMetadata = Record<string, string | number | boolean>;

function csrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function post(entry: QueuedFeedback): Promise<SendResult> {
    let response: globalThis.Response;

    try {
        response = await fetch(`/recommendations/${entry.recommendation_id}/feedback`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                event: entry.event,
                metadata: entry.metadata,
                occurred_at: entry.occurred_at,
            }),
        });
    } catch {
        return 'unreachable'; // no answer at all — the dead zone, keep it queued
    }

    if (response.ok) return 'sent';

    // 5xx and 429 are the server having a bad moment; it may take this later.
    // A 4xx will be a 4xx forever, so keeping it would wedge the queue behind it.
    return response.status >= 500 || response.status === 429 ? 'unreachable' : 'rejected';
}

/**
 * Record a tap. Resolves once it is durable — NOT once it is delivered.
 *
 * Callers do not await the network and must not: the UI has already moved on, and
 * in a dead zone there is nothing to wait for.
 */
export function sendFeedback(recommendationId: string | null, event: string, metadata: FeedbackMetadata = {}): void {
    if (recommendationId === null) return;

    enqueue({ recommendation_id: recommendationId, event, metadata });

    if (!navigator.onLine) return; // it is on disk; the reconnect handler will carry it

    void flush();
}

let flushing = false;

/** Drain the queue. Guarded, because 'online' can fire more than once. */
export async function flush(): Promise<void> {
    if (flushing) return;

    flushing = true;

    try {
        const { sent } = await flushQueue(post);

        // The screen was rendered before those taps landed, so it is now a beat
        // behind the ledger — a Remove made in a dead zone would reappear on
        // reconnect and read as "it didn't work". Let the page catch up.
        if (sent > 0) {
            router.reload();
        }
    } finally {
        flushing = false;
    }
}

/** Called once at boot (app.tsx): carry the dead zone's taps home on reconnect. */
export function registerFeedbackFlush(): void {
    void flush(); // anything stranded by a previous session

    window.addEventListener('online', () => void flush());
}
