import type { StyleSpecification } from 'maplibre-gl';

/**
 * The paper map style (DESIGN §3, SCREENS S3).
 *
 * Raster OSM tiles cannot be restyled beyond crude CSS filters, so the warm paper
 * look requires vector tiles + a style we own. This is that style: an OpenMapTiles
 * schema styled entirely from OUR design tokens, so the map is never "a map with a
 * sepia filter" — it is drawn in the same ink and paper as the cards beside it.
 *
 * Tiles: OpenFreeMap (free, no API key, ODbL-attributable). The fallback/cost lever
 * is self-hosted Protomaps; only `TILE_SOURCE` and `GLYPHS` would change.
 *
 * Deliberately label-light. This basemap exists to give the pins somewhere to sit —
 * street names and POI labels would compete with the thing we actually want read.
 * We keep water and place names; we drop OSM's POI layer entirely, which is also
 * why the map never shows a restaurant we did not choose to recommend.
 */

const TILE_SOURCE = 'https://tiles.openfreemap.org/planet';
const GLYPHS = 'https://tiles.openfreemap.org/fonts/{fontstack}/{range}.pbf';

/** ODbL requires the notice to be visible, not buried in an about page (ODBL-REVIEW §6). */
export const MAP_ATTRIBUTION = '© OpenStreetMap contributors · OpenFreeMap';

export interface PaperPalette {
    bg: string;
    road: string;
    green: string;
    water: string;
    ink: string;
    meta: string;
    borderSoft: string;
    borderStrong: string;
}

/**
 * Pull the palette from the live CSS custom properties rather than restating the
 * hexes here. One consequence worth having: the dark theme, and any future token
 * change, reaches the map for free instead of drifting out of sync with the cards.
 */
export function readPaperPalette(element: HTMLElement = document.documentElement): PaperPalette {
    const style = getComputedStyle(element);
    const token = (name: string, fallback: string) => style.getPropertyValue(name).trim() || fallback;

    return {
        bg: token('--map-bg', '#efe8d8'),
        road: token('--map-road', '#e4dac4'),
        green: token('--map-green', '#dce3d6'),
        water: token('--map-water', '#cfd8d3'),
        ink: token('--ink', '#3b2f24'),
        meta: token('--meta', '#6e6149'),
        borderSoft: token('--border-soft', '#efe4cc'),
        borderStrong: token('--border-strong', '#cbbb9c'),
    };
}

export function buildPaperStyle(palette: PaperPalette): StyleSpecification {
    return {
        version: 8,
        glyphs: GLYPHS,
        sources: {
            openmaptiles: { type: 'vector', url: TILE_SOURCE, attribution: MAP_ATTRIBUTION },
        },
        layers: [
            { id: 'background', type: 'background', paint: { 'background-color': palette.bg } },

            {
                id: 'landcover',
                type: 'fill',
                source: 'openmaptiles',
                'source-layer': 'landcover',
                filter: ['in', 'class', 'wood', 'grass', 'scrub', 'farmland'],
                paint: { 'fill-color': palette.green, 'fill-opacity': 0.7 },
            },
            {
                id: 'park',
                type: 'fill',
                source: 'openmaptiles',
                'source-layer': 'park',
                paint: { 'fill-color': palette.green, 'fill-opacity': 0.8 },
            },
            {
                id: 'water',
                type: 'fill',
                source: 'openmaptiles',
                'source-layer': 'water',
                paint: { 'fill-color': palette.water },
            },
            {
                id: 'waterway',
                type: 'line',
                source: 'openmaptiles',
                'source-layer': 'waterway',
                paint: { 'line-color': palette.water, 'line-width': ['interpolate', ['linear'], ['zoom'], 10, 0.6, 16, 3] },
            },

            // Buildings are a texture here, not a subject — a faint block at the zoom
            // where a walker is orienting, and nothing at all further out.
            {
                id: 'building',
                type: 'fill',
                source: 'openmaptiles',
                'source-layer': 'building',
                minzoom: 14,
                paint: {
                    'fill-color': palette.borderSoft,
                    'fill-opacity': ['interpolate', ['linear'], ['zoom'], 14, 0, 16, 0.55],
                },
            },

            // Roads: one ink-on-paper weight, widened by class rather than recoloured.
            // A walking map needs the shape of the street grid, not its road hierarchy.
            {
                id: 'road-minor',
                type: 'line',
                source: 'openmaptiles',
                'source-layer': 'transportation',
                filter: ['in', 'class', 'minor', 'service', 'path', 'track'],
                minzoom: 13,
                paint: {
                    'line-color': palette.road,
                    'line-width': ['interpolate', ['linear'], ['zoom'], 13, 0.5, 18, 6],
                },
            },
            {
                id: 'road-major',
                type: 'line',
                source: 'openmaptiles',
                'source-layer': 'transportation',
                filter: ['in', 'class', 'motorway', 'trunk', 'primary', 'secondary', 'tertiary'],
                paint: {
                    'line-color': palette.road,
                    'line-width': ['interpolate', ['linear'], ['zoom'], 8, 0.8, 14, 4, 18, 14],
                },
            },

            {
                id: 'boundary',
                type: 'line',
                source: 'openmaptiles',
                'source-layer': 'boundary',
                filter: ['<=', 'admin_level', 4],
                paint: { 'line-color': palette.borderStrong, 'line-width': 0.8, 'line-dasharray': [3, 2] },
            },

            {
                id: 'water-name',
                type: 'symbol',
                source: 'openmaptiles',
                'source-layer': 'water_name',
                layout: {
                    'text-field': ['get', 'name'],
                    'text-font': ['Noto Sans Italic'],
                    'text-size': 11,
                    'text-letter-spacing': 0.1,
                },
                paint: { 'text-color': palette.meta, 'text-halo-color': palette.water, 'text-halo-width': 1 },
            },
            {
                id: 'place-name',
                type: 'symbol',
                source: 'openmaptiles',
                'source-layer': 'place',
                filter: ['in', 'class', 'city', 'town', 'village', 'suburb', 'neighbourhood'],
                layout: {
                    'text-field': ['get', 'name'],
                    'text-font': ['Noto Sans Regular'],
                    'text-size': ['interpolate', ['linear'], ['zoom'], 10, 10, 15, 13],
                    'text-letter-spacing': 0.12,
                    'text-transform': 'uppercase',
                    'text-max-width': 8,
                },
                paint: { 'text-color': palette.meta, 'text-halo-color': palette.bg, 'text-halo-width': 1.5 },
            },
        ],
    };
}
