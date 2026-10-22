<?php

namespace App\Enums;

enum ServiceStatus: string
{
    case Green = 'green';
    case Yellow = 'yellow';
    case Red = 'red';
    case Unknown = 'unknown';

    /**
     * Roll dependency statuses up to one: the most severe evaluated status wins.
     * Unknown dependencies (unsupported or unresolvable) are ignored unless nothing else was evaluated.
     *
     * @param  iterable<self>  $statuses
     */
    public static function worst(iterable $statuses): self
    {
        $evaluated = collect($statuses)->reject(fn (self $status) => $status === self::Unknown);

        foreach ([self::Red, self::Yellow, self::Green] as $status) {
            if ($evaluated->contains($status)) {
                return $status;
            }
        }

        return self::Unknown;
    }
}
