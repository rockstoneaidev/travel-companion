<?php

declare(strict_types=1);

namespace App\Domain\Sources\Enums;

/**
 * What a local news item is telling a traveller (E39).
 *
 * These are the kinds that CHANGE A PLAN — a road you cannot take, a museum that is shut
 * today, a strike that grounds the trains. A festival is an *event* (its own path); this
 * enum is specifically the disruptive tail, because disruption is the half of local news
 * that is urgent and the half a companion is negligent to stay quiet about.
 */
enum LocalAlertKind: string
{
    case Closure = 'closure';         // a place or road shut — travaux, fermeture, avstängd
    case Strike = 'strike';           // grève, strejk — transport a traveller was counting on
    case Disruption = 'disruption';   // delays, diversions, cancellations short of a full strike
    case Hazard = 'hazard';           // flood, fire, storm warning — safety, not inconvenience

    /**
     * How loudly this kind argues for interrupting somebody.
     *
     * NOT a gate — the gates are NotificationPolicy's, and they are hard. This is the raw
     * urgency the alert carries INTO the policy, where quiet hours and the daily budget can
     * still refuse it. A hazard is the one kind whose urgency is about safety rather than a
     * spoiled afternoon, and it is scored accordingly.
     */
    public function baseUrgency(): float
    {
        return match ($this) {
            self::Hazard => 0.95,
            self::Strike => 0.80,
            self::Closure => 0.70,
            self::Disruption => 0.60,
        };
    }
}
