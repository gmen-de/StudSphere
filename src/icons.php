<?php

declare(strict_types=1);

function getNavIcon(string $key): string
{
    $icons = [
        'build' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7"/><path d="M6 10v9h12v-9"/><path d="M10 19v-5h4v5"/></svg>',
        'sets' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8l8-4 8 4"/><rect x="4" y="8" width="16" height="12" rx="1"/><path d="M12 4v16"/></svg>',
        'bricks' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="7" rx="1.5"/><circle cx="9" cy="9" r="1.6" fill="currentColor" stroke="none"/><circle cx="15" cy="9" r="1.6" fill="currentColor" stroke="none"/></svg>',
        'minifigs' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5.5" r="2.5"/><path d="M8 10h8v5H8z"/><path d="M9 15v5"/><path d="M15 15v5"/><path d="M6 11l2 2"/><path d="M18 11l-2 2"/></svg>',
        'my_sets' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8l8-4 8 4"/><rect x="4" y="8" width="16" height="12" rx="1"/><path d="M12 4v16"/><circle cx="18.5" cy="6" r="2.4" fill="currentColor" stroke="none"/></svg>',
        'my_bricks' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="7" rx="1.5"/><circle cx="9" cy="9" r="1.6" fill="currentColor" stroke="none"/><circle cx="15" cy="9" r="1.6" fill="currentColor" stroke="none"/><circle cx="19" cy="6" r="2.4" fill="currentColor" stroke="none"/></svg>',
        'locations' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="16" rx="1"/><path d="M4 10h16"/><path d="M4 16h16"/></svg>',
        'collection' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="7" height="7" rx="1"/><rect x="13" y="4" width="7" height="7" rx="1"/><rect x="4" y="13" width="7" height="7" rx="1"/><rect x="13" y="13" width="7" height="7" rx="1"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><circle cx="9" cy="6" r="2" fill="currentColor" stroke="none"/><line x1="4" y1="12" x2="20" y2="12"/><circle cx="15" cy="12" r="2" fill="currentColor" stroke="none"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="7" cy="18" r="2" fill="currentColor" stroke="none"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H5v16h4"/><path d="M13 12h8"/><path d="M18 8l4 4-4 4"/></svg>',
    ];

    return $icons[$key] ?? '';
}

function getFlagIcon(string $locale): string
{
    $flags = [
        'de' => '<svg viewBox="0 0 30 18"><rect width="30" height="18" fill="#FFCE00"/><rect width="30" height="12" fill="#DD0000"/><rect width="30" height="6" fill="#000000"/></svg>',
        'en' => '<svg viewBox="0 0 30 18"><rect width="30" height="18" fill="#00247D"/><path d="M0 0L30 18M30 0L0 18" stroke="#ffffff" stroke-width="4"/><path d="M0 0L30 18M30 0L0 18" stroke="#CF142B" stroke-width="2"/><path d="M15 0V18M0 9H30" stroke="#ffffff" stroke-width="6"/><path d="M15 0V18M0 9H30" stroke="#CF142B" stroke-width="3.6"/></svg>',
    ];

    return $flags[$locale] ?? '';
}
