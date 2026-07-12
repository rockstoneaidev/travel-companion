import { cn } from '@/lib/utils';

// The design system has no skeleton shimmer: loading states use the static paper-stripe
// placeholder (DESIGN.md §2.5).
function Skeleton({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
    return <div className={cn('paper-stripe rounded-card', className)} {...props} />;
}

export { Skeleton };
