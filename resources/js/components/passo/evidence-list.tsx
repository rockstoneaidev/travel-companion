import { cn } from '@/lib/utils';
import { SectionLabel } from './section-label';

export interface EvidenceItem {
    /** e.g. "Open until 19:00 — parish site, checked 16:50" */
    text: string;
}

/**
 * Source transparency block (DESIGN §3, PRD §16): every fact shown with its source
 * and checked-at time. Facts never originate from the LLM.
 */
export function EvidenceList({ items, className, ...props }: React.ComponentProps<'div'> & { items: EvidenceItem[] }) {
    return (
        <div className={cn('space-y-2', className)} {...props}>
            <SectionLabel>Evidence</SectionLabel>
            <ul className="space-y-1.5">
                {items.map((item, i) => (
                    <li key={i} className="text-meta text-xs">
                        {item.text}
                    </li>
                ))}
            </ul>
        </div>
    );
}
