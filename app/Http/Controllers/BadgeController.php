<?php

namespace App\Http\Controllers;

use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Http\Response;

class BadgeController extends Controller
{
    /**
     * Label and fill per status, matching the dashboard's wording.
     */
    private const LOOK = [
        ServiceStatus::Red->value => ['patch required', '#d32f2f'],
        ServiceStatus::Yellow->value => ['update available', '#b26a00'],
        ServiceStatus::Green->value => ['up to date', '#2e7d32'],
        ServiceStatus::Unknown->value => ['not evaluated', '#6b7280'],
    ];

    /**
     * A shields-style SVG of the service's status for READMEs. Public, so the unguessable token is the only key.
     */
    public function __invoke(string $token): Response
    {
        $service = Service::where('badge_token', $token)->firstOrFail();
        [$label, $color] = self::LOOK[$service->status->value];

        // ponytail: widths from a fixed 6.5px per character, fine for these short labels; measure glyphs if labels become user text.
        $left = 'patchwatch';
        $leftWidth = (int) ceil(strlen($left) * 6.5) + 12;
        $rightWidth = (int) ceil(strlen($label) * 6.5) + 12;
        $width = $leftWidth + $rightWidth;

        $svg = <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="20" role="img" aria-label="{$left}: {$label}">
          <title>{$left}: {$label}</title>
          <clipPath id="r"><rect width="{$width}" height="20" rx="3"/></clipPath>
          <g clip-path="url(#r)">
            <rect width="{$leftWidth}" height="20" fill="#555"/>
            <rect x="{$leftWidth}" width="{$rightWidth}" height="20" fill="{$color}"/>
          </g>
          <g fill="#fff" font-family="Verdana,DejaVu Sans,sans-serif" font-size="11" text-anchor="middle">
            <text x="{$this->middle(0, $leftWidth)}" y="14">{$left}</text>
            <text x="{$this->middle($leftWidth, $rightWidth)}" y="14">{$label}</text>
          </g>
        </svg>
        SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            // GitHub's image proxy honours this, so READMEs pick up a new status within minutes.
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    private function middle(int $start, int $width): float
    {
        return $start + $width / 2;
    }
}
