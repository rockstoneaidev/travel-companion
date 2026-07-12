import { cn } from '@/lib/utils';

interface ProgressSegmentsProps extends React.ComponentProps<'div'> {
    total: number;
    /** 1-based index of the current step; segments ≤ current render filled. */
    current: number;
}

/** Calibration progress: equal flex segments, done/current = terracotta (DESIGN §3). */
export function ProgressSegments({ total, current, className, ...props }: ProgressSegmentsProps) {
    return (
        <div role="progressbar" aria-valuemin={1} aria-valuemax={total} aria-valuenow={current} className={cn('flex gap-1.5', className)} {...props}>
            {Array.from({ length: total }, (_, i) => (
                <div key={i} className={cn('h-[3px] flex-1 rounded-[2px]', i < current ? 'bg-terracotta' : 'bg-border')} />
            ))}
        </div>
    );
}
