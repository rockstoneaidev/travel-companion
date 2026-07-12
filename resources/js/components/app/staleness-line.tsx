import { humanAge } from '@/hooks/use-online';
import { cn } from '@/lib/utils';

/**
 * The offline state, in the app's own voice (SCREENS S11).
 *
 * Not a spinner, not an error banner, not a sad illustration: a sentence that
 * admits what it doesn't know. "I can't check right now" is the honest thing, and
 * an assistant that says so is more trustworthy than one that quietly shows you
 * yesterday's answer as though it were today's.
 */
export function StalenessLine({ lastFreshAt, className }: { lastFreshAt: Date | null; className?: string }) {
    return (
        <p className={cn('text-quiet border-border-soft border-y py-2 text-center font-serif text-xs italic', className)}>
            {lastFreshAt === null
                ? "I can't check right now — this is everything I had."
                : `As of ${humanAge(lastFreshAt)} — I can't check right now.`}
        </p>
    );
}
