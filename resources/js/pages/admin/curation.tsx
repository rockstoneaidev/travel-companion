import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Curation', href: '/admin/curation' },
];

interface EvidenceRow {
    url: string;
    source_type: string;
    license: string;
    excerpt: string;
}

interface CurationItem {
    id: string;
    title: string;
    claim: string;
    facets: string[];
    evidence: EvidenceRow[];
    status: string;
    authored_by: string;
    region: string;
    place_name: string | null;
}

export default function AdminCuration({ items, approvedCount }: { items: CurationItem[]; approvedCount: number }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Curation" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <p className="text-muted-foreground text-sm">
                    {items.length} awaiting review · {approvedCount} approved. Approval makes a claim Tier-A evidence — read it like it will be spoken
                    to a traveler, because it will.
                </p>

                {items.map((item) => (
                    <div key={item.id} className="rounded-xl border p-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">{item.title}</span>
                            <Badge variant={item.status === 'in_review' ? 'default' : 'secondary'}>{item.status}</Badge>
                            <Badge variant="outline">{item.authored_by}</Badge>
                            <span className="text-muted-foreground text-xs">{item.region}</span>
                            {item.place_name !== null ? (
                                <span className="text-muted-foreground text-xs">→ {item.place_name}</span>
                            ) : (
                                <span className="text-destructive text-xs">no canonical place</span>
                            )}
                        </div>

                        <p className="mt-2 text-sm">{item.claim}</p>
                        <p className="text-muted-foreground mt-1 text-xs">{item.facets.join(' · ')}</p>

                        {item.evidence.map((row, i) => (
                            <p key={i} className="text-muted-foreground mt-2 border-l-2 pl-2 text-xs">
                                “{row.excerpt}” —{' '}
                                <a href={row.url} target="_blank" rel="noreferrer" className="underline">
                                    {row.source_type}
                                </a>{' '}
                                ({row.license})
                            </p>
                        ))}

                        <div className="mt-3 flex gap-2">
                            {item.status === 'in_review' && (
                                <Button size="sm" onClick={() => router.put(`/admin/curation/${item.id}/approve`, {}, { preserveScroll: true })}>
                                    Approve
                                </Button>
                            )}
                            {(item.status === 'needs_grounding' || item.status === 'draft') && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => router.post(`/admin/curation/${item.id}/ground`, {}, { preserveScroll: true })}
                                >
                                    Retry grounding
                                </Button>
                            )}
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => router.put(`/admin/curation/${item.id}/reject`, {}, { preserveScroll: true })}
                            >
                                Reject
                            </Button>
                        </div>
                    </div>
                ))}
            </div>
        </AppLayout>
    );
}
