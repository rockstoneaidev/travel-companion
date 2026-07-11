/**
 * The Passo kit (DESIGN.md §3).
 *
 * "Passo" is the permanent internal codename of the design system — namespaces, docs and
 * tokens keep it whatever the product ends up being called. The market-facing wordmark
 * comes from `APP_NAME` (see `useAppName`), never from this namespace.
 */
export { AppHeader, Wordmark, type AppHeaderProps } from './app-header';
export { ChoicePill, PassoButton, type ChoicePillProps, type PassoButtonProps } from './button';
export { EditorialLede, EvidenceList, ProgressSegments, WhyYou } from './editorial';
export { EmptyFeed, type EmptyFeedProps } from './empty-feed';
export { MapAttribution, MapCanvasPlaceholder, MapPin, type MapPinKind, type MapPinProps } from './map-pin';
export { ContextStamp, EndNote, FacetLabels, MetaRow, PaperPlaceholder } from './meta';
export { OpportunityCard, OpportunityCardPlaceholder, type OpportunityCardProps } from './opportunity-card';
export { PassoShell, type PassoShellProps } from './passo-shell';
export { PASSO_TABS, SideRail, TabBar, type SideRailProps } from './tab-bar';
export { UrgencyHeader, type UrgencyHeaderProps } from './urgency';
