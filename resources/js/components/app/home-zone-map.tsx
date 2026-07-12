import { buildPaperStyle, MAP_ATTRIBUTION, readPaperPalette } from '@/lib/paper-map-style';
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { useEffect, useRef } from 'react';

/**
 * Pick your home by pointing at it (SCREENS S10).
 *
 * The home zone used to be two number fields — Latitude and Longitude — and nobody
 * knows their home address in decimal degrees. That is a developer's console wearing
 * a settings page's clothes: the one control in the app whose whole job is to say
 * "never look here" was the one control a person could not actually use.
 *
 * There is no geocoder here and there will not be one: we hold OSM places, not
 * addresses, and a third-party geocoder would drag the ODbL boundary and somebody's
 * terms of service into the most privacy-sensitive screen we have (ODBL-REVIEW §6).
 *
 * We do not need one. We have our own tiles. So: tap the map. The circle IS the zone —
 * the radius is not an abstract number but the thing you can see, and what falls inside
 * it is what we will never learn from.
 *
 * The chosen point never leaves the browser until you press Save.
 */

interface HomeZoneMapProps {
    value: { lat: number; lng: number } | null;
    radiusMeters: number;
    onPick: (point: { lat: number; lng: number }) => void;
}

/** A circle of `radius` metres, as GeoJSON — MapLibre has no metre-radius circle. */
function circle(lat: number, lng: number, radius: number): GeoJSON.Feature<GeoJSON.Polygon> {
    const points = 64;
    const latRadius = radius / 111_320;
    const lngRadius = radius / (111_320 * Math.cos((lat * Math.PI) / 180));

    const ring: [number, number][] = Array.from({ length: points + 1 }, (_, i) => {
        const angle = (i / points) * 2 * Math.PI;

        return [lng + lngRadius * Math.cos(angle), lat + latRadius * Math.sin(angle)];
    });

    return { type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [ring] } };
}

export default function HomeZoneMap({ value, radiusMeters, onPick }: HomeZoneMapProps) {
    const container = useRef<HTMLDivElement>(null);
    const map = useRef<maplibregl.Map | null>(null);
    const marker = useRef<maplibregl.Marker | null>(null);

    // Read the live handler through a ref: the map is built once, and a handler bound
    // on mount would otherwise call yesterday's closure forever.
    const pick = useRef(onPick);
    pick.current = onPick;

    useEffect(() => {
        if (container.current === null) return;

        // Stockholm only as a place to stand while you find yourself — never a default
        // home. A guessed home zone would silence a neighbourhood nobody asked us to.
        const start = value ?? { lat: 59.3293, lng: 18.0686 };

        const instance = new maplibregl.Map({
            container: container.current,
            style: buildPaperStyle(readPaperPalette()),
            center: [start.lng, start.lat],
            zoom: value === null ? 11 : 14,
            attributionControl: false,
        });

        instance.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
        instance.on('click', (event) => pick.current({ lat: event.lngLat.lat, lng: event.lngLat.lng }));

        instance.on('load', () => {
            instance.addSource('home', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
            instance.addLayer({ id: 'home-fill', type: 'fill', source: 'home', paint: { 'fill-color': '#7a7a52', 'fill-opacity': 0.18 } });
            instance.addLayer({ id: 'home-line', type: 'line', source: 'home', paint: { 'line-color': '#7a7a52', 'line-width': 1.5 } });
        });

        map.current = instance;

        return () => {
            instance.remove();
            map.current = null;
        };
        // Built once. The pin and the circle are updated below, not by rebuilding the map.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // The pin and the circle follow the value — no GL teardown, so tapping the map does
    // not tear down and refetch every tile under your finger.
    useEffect(() => {
        const instance = map.current;
        if (instance === null || value === null) return;

        marker.current?.remove();

        const element = document.createElement('div');
        element.className = 'flex flex-col items-center gap-1';
        element.innerHTML =
            '<div style="width:14px;height:14px;border-radius:9999px;background:var(--card);box-shadow:0 0 0 3px var(--olive)"></div>' +
            '<span style="background:var(--card);color:var(--meta);font-size:11px;letter-spacing:.08em;text-transform:uppercase;border-radius:9999px;padding:2px 8px">home</span>';

        marker.current = new maplibregl.Marker({ element, anchor: 'bottom' }).setLngLat([value.lng, value.lat]).addTo(instance);

        const source = instance.getSource('home') as maplibregl.GeoJSONSource | undefined;
        source?.setData({ type: 'FeatureCollection', features: [circle(value.lat, value.lng, radiusMeters)] });
    }, [value, radiusMeters]);

    return (
        <div className="relative">
            <div ref={container} className="bg-map-bg h-64 w-full rounded-lg" data-testid="home-zone-map" />
            <p className="text-quiet bg-card/80 absolute right-0 bottom-0 z-10 rounded-tl px-1.5 py-0.5 text-[10px]">{MAP_ATTRIBUTION}</p>
        </div>
    );
}
