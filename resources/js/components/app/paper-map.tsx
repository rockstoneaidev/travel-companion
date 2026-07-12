import { buildPaperStyle, MAP_ATTRIBUTION, readPaperPalette } from '@/lib/paper-map-style';
import { cn } from '@/lib/utils';
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { GoNowPin, PlacePin, YouMarker } from './map-pin';

/**
 * The MapLibre layer for S3.
 *
 * This module is the ONLY thing that imports maplibre-gl (~200KB gzipped), so that
 * Vite gives it its own chunk and the feed never pays for a map the user may never
 * open (DESIGN §3). Import it lazily; do not re-export it from the barrel.
 *
 * Pins are portalled into the marker elements rather than rebuilt as raw DOM, which
 * keeps <GoNowPin>/<PlacePin>/<YouMarker> the single definition of what a pin looks
 * like — the map cannot drift away from the design system.
 */

export interface MapItem {
    id: string;
    lat: number;
    lng: number;
    label: string;
    urgent: boolean;
    /** Weighed and held back (the home map's "passed over"). Rendered as a quiet hollow dot. */
    dimmed?: boolean;
}

export interface PaperMapProps {
    items: MapItem[];
    origin: { lat: number; lng: number } | null;
    /** What the origin marker IS. "you" is a claim; do not make it unless it is true. */
    originLabel?: string;
    selectedId: string | null;
    onSelect: (id: string | null) => void;
    className?: string;
}

export default function PaperMap({ items, origin, originLabel = 'you', selectedId, onSelect, className }: PaperMapProps) {
    const container = useRef<HTMLDivElement>(null);
    const map = useRef<maplibregl.Map | null>(null);

    // Marker elements live outside React's tree (MapLibre owns them), so we hold them
    // in state and portal into them once they exist.
    const [pinNodes, setPinNodes] = useState<Record<string, HTMLElement>>({});
    const [originNode, setOriginNode] = useState<HTMLElement | null>(null);

    // The selection handler is re-created on every render; markers are not. Read the
    // live one through a ref so a marker bound on mount never calls a stale closure.
    const select = useRef(onSelect);
    select.current = onSelect;

    /*
     * Build the map from what the pins ARE, not from the identity of the array holding
     * them. `items` is derived in the parent, so it is a new array on every render —
     * and the parent re-renders on every pin tap. Keying the effect on the array itself
     * tore the whole GL context down and rebuilt it each time you tapped a pin: your
     * pan and zoom were thrown away and every tile refetched, mid-interaction.
     */
    const signature = JSON.stringify([origin, items.map((item) => [item.id, item.lat, item.lng, item.urgent, item.dimmed, item.label])]);

    const live = useRef({ items, origin });
    live.current = { items, origin };

    useEffect(() => {
        if (container.current === null) return;

        const { items, origin } = live.current;

        const instance = new maplibregl.Map({
            container: container.current,
            style: buildPaperStyle(readPaperPalette()),
            center: origin !== null ? [origin.lng, origin.lat] : [items[0]?.lng ?? 18.07, items[0]?.lat ?? 59.32],
            zoom: 14,
            attributionControl: false, // ours is rendered below — see the note there
        });

        instance.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
        instance.on('click', () => select.current(null)); // tapping the paper closes the peek

        // A basemap that fails renders as plain paper — which looks like a design choice
        // rather than a broken tile source. Say so out loud instead.
        instance.on('error', (event) => console.error('[paper-map]', event.error?.message ?? event));

        map.current = instance;

        // Frame everything that matters: every pin, plus where the user is standing.
        const points = [...items.map((item) => [item.lng, item.lat] as [number, number])];
        if (origin !== null) points.push([origin.lng, origin.lat]);

        if (points.length > 1) {
            const bounds = points.reduce((box, point) => box.extend(point), new maplibregl.LngLatBounds(points[0], points[0]));
            instance.fitBounds(bounds, { padding: { top: 72, bottom: 220, left: 56, right: 56 }, maxZoom: 16 });
        }

        const nodes: Record<string, HTMLElement> = {};
        const markers: maplibregl.Marker[] = [];

        for (const item of items) {
            const element = document.createElement('div');
            element.addEventListener('click', (event) => {
                event.stopPropagation(); // ...or the map's own click handler closes the peek we just opened
                select.current(item.id);
            });

            markers.push(new maplibregl.Marker({ element, anchor: 'bottom' }).setLngLat([item.lng, item.lat]).addTo(instance));
            nodes[item.id] = element;
        }

        if (origin !== null) {
            const element = document.createElement('div');
            markers.push(new maplibregl.Marker({ element, anchor: 'bottom' }).setLngLat([origin.lng, origin.lat]).addTo(instance));
            setOriginNode(element);
        }

        setPinNodes(nodes);

        // The palette is read once at style build, so a theme flip has to rebuild it.
        const themeWatcher = new MutationObserver(() => instance.setStyle(buildPaperStyle(readPaperPalette())));
        themeWatcher.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

        return () => {
            themeWatcher.disconnect();
            markers.forEach((marker) => marker.remove());
            instance.remove();
            map.current = null;
        };
        // Keyed on `signature`, not on `items`: rebuild only when the pins actually
        // change, never on a re-render that produced a new array of the same pins.
    }, [signature]);

    // Centre the selected pin without yanking the view — ease, and only far enough.
    useEffect(() => {
        const item = live.current.items.find((candidate) => candidate.id === selectedId);
        if (item === undefined || map.current === null) return;

        map.current.easeTo({ center: [item.lng, item.lat], offset: [0, -80], duration: 400 });
    }, [selectedId]);

    return (
        <div className={cn('relative h-full w-full', className)}>
            <div ref={container} className="bg-map-bg h-full w-full" data-testid="paper-map" />

            {items.map((item) => {
                const node = pinNodes[item.id];
                if (node === undefined) return null;

                const pin = item.urgent ? (
                    <GoNowPin label="Go now" className="cursor-pointer" />
                ) : (
                    <PlacePin
                        label={item.label}
                        dimmed={item.dimmed === true}
                        className={cn('cursor-pointer', selectedId !== null && selectedId !== item.id && 'opacity-50')}
                    />
                );

                return createPortal(pin, node, item.id);
            })}

            {originNode !== null && createPortal(<YouMarker label={originLabel} />, originNode, 'origin')}

            {/*
             * ODbL requires this to be visible, and OpenFreeMap requires the credit
             * (ODBL-REVIEW §6). It lives inside the map component precisely so no
             * screen can render our tiles and forget it.
             */}
            <p className="text-quiet bg-card/80 absolute right-0 bottom-0 z-10 rounded-tl px-1.5 py-0.5 text-[10px]">{MAP_ATTRIBUTION}</p>
        </div>
    );
}
