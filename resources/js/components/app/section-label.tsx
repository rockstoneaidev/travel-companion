import { cn } from '@/lib/utils';

/** Caps micro-label: facet rows, "EVIDENCE", "WHY YOU", context stamps (DESIGN §2.3). */
export function SectionLabel({ className, ...props }: React.ComponentProps<'div'>) {
    return <div className={cn('text-facet text-meta font-medium tracking-[.12em] uppercase', className)} {...props} />;
}
