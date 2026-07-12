import { QuietAction, TextAction } from './buttons';

interface VisitPromptCardProps {
    placeName: string;
    onWasThere: () => void;
    onDidntGo: () => void;
}

/**
 * "Were you there?" (SCREENS S4) — a quiet card at the top of NOW, never a
 * modal. The single most valuable tap in the learning loop, so it has to feel
 * like a friend asking rather than a survey.
 *
 * "I was there" is the golden label. "Didn't go" teaches nothing: the user
 * accepted this item, and not making it there says nothing about their taste.
 */
export function VisitPromptCard({ placeName, onWasThere, onDidntGo }: VisitPromptCardProps) {
    return (
        <article className="rounded-card bg-card border-border-soft border border-dashed p-4">
            <p className="text-title text-ink font-serif italic">Did you make it to {placeName}?</p>

            <div className="mt-3 flex items-center gap-4">
                <TextAction onClick={onWasThere}>I was there</TextAction>
                <QuietAction onClick={onDidntGo}>Didn't go</QuietAction>
            </div>
        </article>
    );
}
