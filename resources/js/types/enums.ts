// Mirrors of PHP enums that cross the wire (docs/conventions/02-enums.md).
// Parity with the PHP cases is asserted by tests/Feature/EnumParityTest.php.

export const ROLES = ['admin', 'superadmin'] as const;
export type Role = (typeof ROLES)[number];

export const PERMISSIONS = ['admin_access', 'ops_view', 'users_view', 'users_manage_roles', 'activity_view'] as const;
export type Permission = (typeof PERMISSIONS)[number];

// --- World model (E1) ---

export const APPEAL_FACETS = [
    'history',
    'architecture',
    'nature',
    'scenic',
    'food_drink',
    'art',
    'craft',
    'spiritual',
    'local_life',
    'family',
    'active',
    'offbeat',
    'romantic',
    'educational',
] as const;
export type AppealFacet = (typeof APPEAL_FACETS)[number];

export const PLACE_TYPE_DOMAINS = [
    'religious_sacred',
    'historic_heritage',
    'museum_gallery',
    'nature_landscape',
    'food_drink',
    'arts_culture',
    'architecture_urban',
    'shops_craft',
    'activity_recreation',
    'events',
    'practical',
] as const;
export type PlaceTypeDomain = (typeof PLACE_TYPE_DOMAINS)[number];

export const PLACE_TYPES = [
    'church',
    'cathedral',
    'chapel',
    'monastery',
    'abbey',
    'shrine',
    'temple',
    'sacred_cemetery',
    'castle',
    'fortress',
    'ruin',
    'monument',
    'memorial',
    'archaeological_site',
    'historic_house',
    'city_gate',
    'old_town',
    'art_museum',
    'history_museum',
    'science_museum',
    'local_museum',
    'house_museum',
    'gallery',
    'viewpoint',
    'park',
    'garden',
    'forest',
    'waterfall',
    'lake',
    'beach',
    'cave',
    'cliff',
    'geological_feature',
    'spring',
    'restaurant',
    'cafe',
    'bakery',
    'market',
    'food_producer',
    'winery',
    'brewery',
    'distillery',
    'deli',
    'theatre',
    'concert_hall',
    'cinema',
    'cultural_center',
    'street_art',
    'artist_studio',
    'notable_building',
    'square',
    'bridge',
    'tower',
    'fountain',
    'notable_street',
    'artisan_workshop',
    'bookshop',
    'antique_shop',
    'specialty_shop',
    'craft_studio',
    'walking_trail',
    'cycling_route',
    'beach_recreation',
    'sports_venue',
    'wellness',
    'boat_activity',
    'concert',
    'festival',
    'market_day',
    'exhibition',
    'performance',
    'seasonal_event',
    'toilet',
    'charging_point',
    'pharmacy',
    'shelter',
    'transport_hub',
] as const;
export type PlaceType = (typeof PLACE_TYPES)[number];

export const OPPORTUNITY_KINDS = ['evergreen', 'ephemeral', 'event', 'seasonal'] as const;
export type OpportunityKind = (typeof OPPORTUNITY_KINDS)[number];

export const TRAVEL_MODES = ['walk', 'bike', 'drive'] as const;
export type TravelMode = (typeof TRAVEL_MODES)[number];

export const FEEDBACK_EVENTS = ['accepted', 'saved', 'dismissed', 'visited', 'ignored'] as const;
export type FeedbackEvent = (typeof FEEDBACK_EVENTS)[number];

// --- Trips & explore sessions (E4) ---

export const TRIP_STATUSES = ['planned', 'active', 'completed'] as const;
export type TripStatus = (typeof TRIP_STATUSES)[number];

export const TRIP_SOURCES = ['auto', 'user'] as const;
export type TripSource = (typeof TRIP_SOURCES)[number];

export const EXPLORE_SESSION_STATUSES = ['active', 'ended', 'expired'] as const;
export type ExploreSessionStatus = (typeof EXPLORE_SESSION_STATUSES)[number];

export const MOVEMENT_MODES = ['still', 'walking', 'running', 'cycling', 'driving', 'transit', 'unknown'] as const;
export type MovementMode = (typeof MOVEMENT_MODES)[number];

export const APP_STATES = ['foreground', 'background'] as const;
export type AppState = (typeof APP_STATES)[number];
