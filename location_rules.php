<?php
/** NULL keeps compatibility: all enabled destinations; [] explicitly allows none. */
function locationChangeAllowed(array $source, array $target, $userId, ?string $agent = null): bool
{
    if (($source['changeloc'] ?? '') !== 'onchangeloc' || ($target['changeloc'] ?? '') !== 'onchangeloc'
        || ($target['status'] ?? '') !== 'active' || $source['code_panel'] === $target['code_panel']) { return false; }
    if ($agent !== null && !in_array($target['agent'] ?? '', [$agent, 'all'], true)) { return false; }
    $hidden = json_decode($target['hide_user'] ?? '[]', true);
    if (is_array($hidden) && in_array((string) $userId, array_map('strval', $hidden), true)) { return false; }
    $raw = $source['location_targets'] ?? null;
    if ($raw === null) { return true; }
    $allowed = json_decode($raw, true);
    return is_array($allowed) && in_array((string) $target['code_panel'], array_map('strval', $allowed), true);
}
