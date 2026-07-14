import { buildPaperStyle, MAP_ATTRIBUTION, readPaperPalette } from '@/lib/paper-map-style';
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useEffect, useRef } from 'react';

/**
 * The emulator's map (ADMIN §6, E47).
 *
 * Its own component, and the only thing on the admin side that imports maplibre-gl, so
 * Vite keeps the ~200 KB chunk out of every other console page — the same discipline
 * `paper-map.tsx` applies to the feed.
 *
 * §6 says "MapLibre GL + OSM raster tiles". It gets MapLibre and it gets OSM, but via
 * the OpenFreeMap VECTOR tiles the product already uses: adding a raster host would mean
 * a second tile provider, a second attribution and a second thing to break, to render
 * the same data in a form we could not style. The licence property §6 actually cared
 * about — OSM data, consistent with the ODbL world model, no API key — is unchanged.
 *
 * What is drawn here that is drawn nowhere else: the COVERAGE. `CoverageGeometry` has
 * always known precisely which res-8 hexagons it was about to scout — disc when
 * wandering, cone ahead of a heading, corridor to a destination — and nothing could show
 * it, because nothing had ever asked H3 for a hex boundary. Watching the cone swing as
 * the pin turns is the whole reason this screen exists.
 */

export interface CoverageCell {
    cell: string;
    range: 'near' | 'far';
    geometry: { type: string; coordinates: number[][][] };
}

export interface ServedPin {
    id: string;
    name: string;
    lat: number | null;
    lng: number | null;
    position: number;
    /** What this card actually cost, and what it would have cost cold (COST.md §2.2). */
    billed_micros: number;
    uncached_micros: number;
}

export interface EmulatorMapProps {
    pin: { lat: number; lng: number } | null;
    path: Array<{ lat: number; lng: number }>;
    coverage: CoverageCell[];
    served: ServedPin[];
    /** Click adds a path point; drag moves the pin. Both are how you author a walk. */
    onDropPin: (at: { lat: number; lng: number }) => void;
    onAddPathPoint: (at: { lat: number; lng: number }) => void;
    drawing: boolean;
    className?: string;
}

export default function EmulatorMap({ pin, path, coverage, served, onDropPin, onAddPathPoint, drawing, className }: EmulatorMapProps) {
    const container = useRef<HTMLDivElement | null>(null);
    const map = useRef<maplibregl.Map | null>(null);
    const pinMarker = useRef<maplibregl.Marker | null>(null);
    const placeMarkers = useRef<maplibregl.Marker[]>([]);
    const ready = useRef(false);

    // Handlers live in a ref so the map is built exactly once: rebuilding a MapLibre
    // instance on every parent render would reset the viewport under the operator's hand.
    const handlers = useRef({ onDropPin, onAddPathPoint, drawing });
    handlers.current = { onDropPin, onAddPathPoint, drawing };

    useEffect(() => {
        if (container.current === null || map.current !== null) return;

        const instance = new maplibregl.Map({
            container: container.current,
            style: buildPaperStyle(readPaperPalette()),
            center: pin !== null ? [pin.lng, pin.lat] : [18.0227, 59.3103], // Liljeholmen
            zoom: 12,
            attributionControl: false,
        });

        instance.addControl(new maplibregl.AttributionControl({ compact: true, customAttribution: MAP_ATTRIBUTION }));
        instance.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');

        instance.on('click', (event) => {
            const at = { lat: event.lngLat.lat, lng: event.lngLat.lng };

            if (handlers.current.drawing) {
                handlers.current.onAddPathPoint(at);
            } else {
                handlers.current.onDropPin(at);
            }
        });

        instance.on('load', () => {
            instance.addSource('coverage', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            instance.addSource('path', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });

            // Near vs far is not decoration: it is which scouts run out there
            // (conventions/12 — a café is worth a 300 m detour, a ruined castle 20 km).
            instance.addLayer({
                id: 'coverage-fill',
                type: 'fill',
                source: 'coverage',
                paint: {
                    'fill-color': ['case', ['==', ['get', 'range'], 'near'], '#b45309', '#6b7280'],
                    'fill-opacity': ['case', ['==', ['get', 'range'], 'near'], 0.18, 0.07],
                },
            });
            instance.addLayer({
                id: 'coverage-line',
                type: 'line',
                source: 'coverage',
                paint: { 'line-color': '#b45309', 'line-width': 0.4, 'line-opacity': 0.35 },
            });
            instance.addLayer({
                id: 'path-line',
                type: 'line',
                source: 'path',
                paint: { 'line-color': '#1f2937', 'line-width': 2, 'line-dasharray': [2, 1] },
            });

            ready.current = true;
            syncCoverage(instance, coverage);
            syncPath(instance, path);
        });

        map.current = instance;

        return () => {
            instance.remove();
            map.current = null;
            ready.current = false;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (map.current === null || !ready.current) return;
        syncCoverage(map.current, coverage);
    }, [coverage]);

    useEffect(() => {
        if (map.current === null || !ready.current) return;
        syncPath(map.current, path);
    }, [path]);

    // The pin — draggable, because the fastest way to ask "what's served from over
    // there?" is to put it over there.
    useEffect(() => {
        if (map.current === null) return;

        if (pin === null) {
            pinMarker.current?.remove();
            pinMarker.current = null;

            return;
        }

        if (pinMarker.current === null) {
            const marker = new maplibregl.Marker({ color: '#b45309', draggable: true });
            marker.on('dragend', () => {
                const { lat, lng } = marker.getLngLat();
                handlers.current.onDropPin({ lat, lng });
            });
            marker.setLngLat([pin.lng, pin.lat]).addTo(map.current);
            pinMarker.current = marker;

            return;
        }

        pinMarker.current.setLngLat([pin.lng, pin.lat]);
    }, [pin]);

    // What the position actually served, where it actually is.
    useEffect(() => {
        if (map.current === null) return;

        placeMarkers.current.forEach((marker) => marker.remove());
        placeMarkers.current = served
            .filter((item): item is ServedPin & { lat: number; lng: number } => item.lat !== null && item.lng !== null)
            .map((item) => {
                const element = document.createElement('div');
                element.className = 'rounded-full bg-neutral-900 text-white text-[10px] font-bold grid place-items-center';
                element.style.width = '18px';
                element.style.height = '18px';
                element.title = item.name;
                element.textContent = String(item.position);

                return new maplibregl.Marker({ element }).setLngLat([item.lng, item.lat]).addTo(map.current!);
            });
    }, [served]);

    return <div ref={container} className={className} />;
}

function syncCoverage(map: maplibregl.Map, coverage: CoverageCell[]) {
    const source = map.getSource('coverage') as maplibregl.GeoJSONSource | undefined;

    source?.setData({
        type: 'FeatureCollection',
        features: coverage.map((cell) => ({
            type: 'Feature' as const,
            properties: { range: cell.range },
            geometry: cell.geometry as GeoJSON.Polygon,
        })),
    });
}

function syncPath(map: maplibregl.Map, path: Array<{ lat: number; lng: number }>) {
    const source = map.getSource('path') as maplibregl.GeoJSONSource | undefined;

    source?.setData({
        type: 'FeatureCollection',
        features:
            path.length < 2
                ? []
                : [
                      {
                          type: 'Feature' as const,
                          properties: {},
                          geometry: { type: 'LineString' as const, coordinates: path.map((p) => [p.lng, p.lat]) },
                      },
                  ],
    });
}
