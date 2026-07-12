import { cn } from '@/lib/utils';
import { SectionLabel } from './section-label';

/** The personal-taste explanation, from the explanation endpoint (DESIGN §3). */
export function WhyYou({ children, className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div className={cn('space-y-2', className)} {...props}>
            <SectionLabel>Why you</SectionLabel>
            <p className="text-body-detail text-body font-serif italic">{children}</p>
        </div>
    );
}
