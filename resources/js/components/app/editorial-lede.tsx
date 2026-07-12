import { cn } from '@/lib/utils';

/** The italic serif sentence under the header summarizing the feed — written by the backend voice layer (DESIGN §3). */
export function EditorialLede({ className, ...props }: React.ComponentProps<'p'>) {
    return <p className={cn('text-lede text-body font-serif italic', className)} {...props} />;
}
