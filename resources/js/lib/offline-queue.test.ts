import { beforeEach, describe, expect, it, vi } from 'vitest';
import { enqueue, flushQueue, readQueue, type QueuedFeedback, type SendResult } from './offline-queue';

/*
 * The offline feedback queue (SCREENS S11).
 *
 * "Losing a golden-label tap to a dead zone is not acceptable." These tests are
 * that sentence, made enforceable.
 */

function fakeStore() {
    const data = new Map<string, string>();

    return {
        getItem: (k: string) => data.get(k) ?? null,
        setItem: (k: string, v: string) => void data.set(k, v),
    };
}

let store: ReturnType<typeof fakeStore>;

beforeEach(() => {
    store = fakeStore();
});

const tap = (recommendationId = 'rec-1', event = 'visited') => ({
    recommendation_id: recommendationId,
    event,
    metadata: {},
});

describe('enqueue', () => {
    it('stamps when the tap happened, not when it is sent', () => {
        const entry = enqueue(tap(), store);

        // The server reasons about elapsed time ("Were you there?"), so a tap
        // flushed hours later must still say when it actually happened.
        expect(Date.parse(entry.occurred_at)).toBeCloseTo(Date.now(), -3);
        expect(readQueue(store)).toHaveLength(1);
    });

    it('drops the oldest, never the newest, when it overflows', () => {
        for (let i = 0; i < 205; i++) enqueue(tap(`rec-${i}`), store);

        const queue = readQueue(store);

        // The tap the user just made is the one they are watching.
        expect(queue).toHaveLength(200);
        expect(queue.at(-1)?.recommendation_id).toBe('rec-204');
        expect(queue.some((e) => e.recommendation_id === 'rec-0')).toBe(false);
    });

    it('survives a corrupt queue rather than bricking every future tap', () => {
        store.setItem('feedback:queue:v1', '{not json');

        expect(readQueue(store)).toEqual([]);
        expect(enqueue(tap(), store).recommendation_id).toBe('rec-1');
    });
});

describe('flushQueue', () => {
    it('sends what it has, oldest first, and empties the queue', async () => {
        enqueue(tap('rec-1'), store);
        enqueue(tap('rec-2'), store);

        const seen: string[] = [];
        const result = await flushQueue(async (e: QueuedFeedback): Promise<SendResult> => {
            seen.push(e.recommendation_id);

            return 'sent';
        }, store);

        expect(seen).toEqual(['rec-1', 'rec-2']);
        expect(result).toMatchObject({ sent: 2, rejected: 0, remaining: 0 });
    });

    it('KEEPS a tap the network never took — this is the whole point', async () => {
        enqueue(tap('rec-1'), store);
        enqueue(tap('rec-2'), store);

        const result = await flushQueue(async () => 'unreachable', store);

        // Still in a dead zone. Nothing was delivered, so nothing may be dropped.
        expect(result).toMatchObject({ sent: 0, remaining: 2 });
        expect(readQueue(store).map((e) => e.recommendation_id)).toEqual(['rec-1', 'rec-2']);
    });

    it('stops at the first unreachable entry instead of burning the rest', async () => {
        enqueue(tap('rec-1'), store);
        enqueue(tap('rec-2'), store);
        enqueue(tap('rec-3'), store);

        const send = vi.fn<(e: QueuedFeedback) => Promise<SendResult>>().mockResolvedValueOnce('sent').mockResolvedValueOnce('unreachable');

        const result = await flushQueue(send, store);

        // rec-1 delivered; rec-2 hit the dead zone. rec-3 must not even be tried —
        // and above all must not be discarded.
        expect(send).toHaveBeenCalledTimes(2);
        expect(result).toMatchObject({ sent: 1, remaining: 2 });
        expect(readQueue(store).map((e) => e.recommendation_id)).toEqual(['rec-2', 'rec-3']);
    });

    it('drops a tap the server refused, so one bad entry cannot wedge the queue', async () => {
        enqueue(tap('rec-gone'), store);
        enqueue(tap('rec-good'), store);

        const send = vi
            .fn<(e: QueuedFeedback) => Promise<SendResult>>()
            .mockResolvedValueOnce('rejected') // e.g. the recommendation was deleted — a 4xx forever
            .mockResolvedValueOnce('sent');

        const result = await flushQueue(send, store);

        // Retrying a permanent rejection on every reconnect is an infinite loop
        // that also blocks every entry behind it.
        expect(result).toMatchObject({ sent: 1, rejected: 1, remaining: 0 });
    });

    it('does not re-send what it already delivered if the flush is interrupted', async () => {
        enqueue(tap('rec-1'), store);
        enqueue(tap('rec-2'), store);

        // First flush: rec-1 lands, then the tab dies (network drops).
        await flushQueue(
            vi.fn<(e: QueuedFeedback) => Promise<SendResult>>().mockResolvedValueOnce('sent').mockResolvedValueOnce('unreachable'),
            store,
        );

        // Second flush, back online. rec-1 was persisted as delivered, so it must
        // not be sent twice — a double "visited" is a doubled golden label.
        const seen: string[] = [];
        await flushQueue(async (e) => {
            seen.push(e.recommendation_id);

            return 'sent';
        }, store);

        expect(seen).toEqual(['rec-2']);
    });
});
