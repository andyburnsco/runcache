<?php

/**
 * RunCache WordPress plugin
 *
 * Loads one site's entry from the generated wp-content/runcache-config.php,
 * whose 'sites' map holds every single-site and multisite key.
 *
 * @package RunCache
 * @since 0.0.8
 */

// phpcs:ignoreFile WordPress.WP.AlternativeFunctions -- Drop-in runs before WordPress loads, wp_ functions not available

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('RUNCACHE_CACHE_DIRECTORY')) {
    define('RUNCACHE_CACHE_DIRECTORY', WP_CONTENT_DIR . '/cache/runcache/');
}

if (!defined('RUNCACHE_CONFIG_FILE')) {
    define('RUNCACHE_CONFIG_FILE', WP_CONTENT_DIR . '/runcache-config.php');
}

/* [RUNCACHE_INLINE_DATA: runcache-rule-defaults] */
// Dropin.php replaces this block with the rule defaults as a literal array.
// Standalone runs load the defaults by require.
$runcache_defaults_file = __DIR__ . '/runcache-rule-defaults.php';
$runcache_fallback_rules = is_readable($runcache_defaults_file)
    ? require $runcache_defaults_file
    : [];
if (!is_array($runcache_fallback_rules)) {
    $runcache_fallback_rules = [];
}
/* [/RUNCACHE_INLINE_DATA: runcache-rule-defaults] */

// Safety rules that the dashboard cannot disable. The filter checks them.
$GLOBALS['runcache_hardcoded_exclusions'] = is_array($runcache_fallback_rules['hardcoded'] ?? null)
    ? $runcache_fallback_rules['hardcoded']
    : [];

// The fallback below needs only the configurable rules.
$runcache_fallback_rules = is_array($runcache_fallback_rules['rules'] ?? null)
    ? $runcache_fallback_rules['rules']
    : [];

// Version of the page-cache meta payload. Bump it to retire older entries.
if (!defined('RUNCACHE_PAGE_META_SCHEMA')) {
    define('RUNCACHE_PAGE_META_SCHEMA', 1);
}

// ===== HELPER FUNCTIONS =====

if (!function_exists('runcache_get_server_var')) {
    /**
     * Reads a server variable and returns a trimmed string.
     * The drop-in runs before WordPress loads, so WordPress helpers are not available.
     *
     * @param string $key Server variable key
     * @return string Trimmed value
     */
    function runcache_get_server_var(string $key): string
    {
        $value = $_SERVER[$key] ?? '';

        // Strip slashes (legacy PHP support).
        if (is_string($value)) {
            $value = stripslashes($value);
        }

        // Trim whitespace
        return trim($value);
    }
}

if (!function_exists('runcache_get_canonical_path')) {
    /**
     * Returns the request path, or null when the request is not canonical.
     * A null result means the request must not read or write the cache.
     *
     * The raw REQUEST_URI is used. stripslashes() would rewrite a literal
     * backslash before the check. parse_url() reads a leading '//' as an
     * authority and returns no path, which collapses the cache key.
     *
     * @return string|null Path with its leading slash, or null.
     */
    function runcache_get_canonical_path(): ?string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? null;

        if (!is_string($uri) || $uri === '' || $uri[0] !== '/') {
            return null;
        }

        // Control bytes, space, and backslash never belong in a canonical path.
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $uri) === 1) {
            return null;
        }

        // Split on the first '?' only. A later '?' is query data.
        $query_start = strpos($uri, '?');
        $path = $query_start === false ? $uri : substr($uri, 0, $query_start);

        if ($path === '' || str_contains($path, '//')) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }

        return $path;
    }
}

if (!function_exists('runcache_response_content_type')) {
    /**
     * Returns the lowercase media type of a header map, or null when absent.
     *
     * @param array<int|string, mixed> $headers Captured response headers.
     * @return string|null Media type without parameters, or null.
     */
    function runcache_response_content_type(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Content-Type') !== 0) {
                continue;
            }

            $media_type = strtolower(trim(explode(';', (string) $value, 2)[0]));

            return $media_type !== '' ? $media_type : null;
        }

        return null;
    }
}

if (!function_exists('runcache_content_type_is_cacheable')) {
    /**
     * Reports whether a response may enter the HTML page cache.
     * An absent Content-Type keeps the historical behaviour.
     *
     * @param array<int|string, mixed> $headers Captured response headers.
     * @return bool
     */
    function runcache_content_type_is_cacheable(array $headers): bool
    {
        $media_type = runcache_response_content_type($headers);

        if ($media_type === null) {
            return true;
        }

        $allowed = ['text/html', 'application/xhtml+xml'];

        // The drop-in runs before WordPress loads, so this filter is optional.
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('runcache_cacheable_content_types', $allowed);
            if (is_array($filtered)) {
                $allowed = array_map(static function (mixed $type): string {
                    return strtolower(trim((string) $type));
                }, $filtered);
            }
        }

        return in_array($media_type, $allowed, true);
    }
}

if (!function_exists('runcache_page_meta_is_current')) {
    /**
     * Reports whether a stored page-cache meta belongs to the current schema.
     * Entries written before the marker existed stay valid.
     *
     * @param array<string, mixed> $meta Decoded meta payload.
     * @return bool
     */
    function runcache_page_meta_is_current(array $meta): bool
    {
        return !isset($meta['schema']) || $meta['schema'] === RUNCACHE_PAGE_META_SCHEMA;
    }
}

if (!function_exists('runcache_normalize_list')) {
    /**
     * Normalizes a list value to an array of trimmed strings.
     * List values can be arrays or newline-separated strings.
     *
     * @param mixed $value String or array value from config
     * @return array<int, string>
     */
    function runcache_normalize_list(mixed $value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } elseif (is_string($value)) {
            $parts = preg_split('/\r?\n/', $value);
        } else {
            return [];
        }

        if (!is_array($parts)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $parts), static function ($item) {
            return $item !== '';
        }));
    }
}

if (!function_exists('runcache_sanitize_query_params')) {
    /**
     * Sanitizes raw query parameters before the cache key uses them.
     * Plain PHP only. The drop-in runs before WordPress loads.
     *
     * @param array<int|string, mixed> $params Raw query parameters, typically $_GET
     * @return array<int|string, mixed> Sanitized parameters
     */
    function runcache_sanitize_query_params(array $params): array
    {
        // Caps stop nested-array floods.
        $max_nodes = 100;
        $max_depth = 3;
        $max_key_length = 100;
        $remaining = $max_nodes;

        // Keys keep only URL-safe name characters. A length cap applies.
        $sanitize_key = static function (mixed $key) use ($max_key_length): string {
            $name = (string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $key);
            return substr($name, 0, $max_key_length);
        };

        // Values drop control characters. No truncation keeps md5 keys distinct.
        $sanitize_value = static function (mixed $value): string {
            if (!is_scalar($value)) {
                return '';
            }
            return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value);
        };

        $walk = null;
        $walk = static function (mixed $value, int $depth) use (&$walk, &$remaining, $sanitize_key, $sanitize_value, $max_depth): mixed {
            if ($remaining <= 0) {
                return null;
            }
            $remaining--;

            if (is_array($value)) {
                if ($depth >= $max_depth) {
                    return null;
                }
                $clean = [];
                foreach ($value as $key => $item) {
                    $key_name = $sanitize_key($key);
                    if ($key_name === '' || array_key_exists($key_name, $clean)) {
                        continue;
                    }
                    $clean_item = $walk($item, $depth + 1);
                    if ($clean_item === null || (is_array($clean_item) && $clean_item === [])) {
                        continue;
                    }
                    $clean[$key_name] = $clean_item;
                }
                return $clean;
            }

            // Keep an empty value. '?rsd' is a real parameter, not no parameter.
            return $sanitize_value($value);
        };

        $sanitized = $walk($params, 0);
        return is_array($sanitized) ? $sanitized : [];
    }
}

if (!function_exists('runcache_filter_query_params')) {
    /**
     * Filters query parameters for the cache key.
     * runcache_should_cache() rejects excluded keys before this runs.
     *
     * @param array<int|string, mixed> $params Raw query parameters
     * @param array<string, mixed>     $config RunCache config
     * @return array<int|string, mixed> Filtered query parameters
     */
    function runcache_filter_query_params(array $params, array $config): array
    {
        $ignored = !empty($config['ignore_query_onn'])
            ? runcache_normalize_list($config['ignore_query_mch'] ?? [])
            : [];
        $allowed = !empty($config['allow_query_onn'])
            ? runcache_normalize_list($config['allow_query_mch'] ?? [])
            : [];

        if ($ignored !== []) {
            $params = array_diff_key($params, array_flip($ignored));
        }

        // With no allow list, drop all remaining params. Random query strings
        // then cannot flood the cache with variants.
        if (empty($config['allow_query_onn'])) {
            return [];
        }

        if ($allowed !== []) {
            $params = array_intersect_key($params, array_flip($allowed));
        }

        return $params;
    }
}

if (!function_exists('runcache_normalize_config_component')) {
    /**
     * Normalizes one config key component (host or path segment).
     *
     * Must stay in sync with Multisite::normalize_config_key_component() in
     * src/Integrations/Multisite.php.
     *
     * @param string $value Raw component value.
     * @return string Normalized component, possibly empty.
     */
    function runcache_normalize_config_component(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';

        return trim($value, '-');
    }
}

if (!function_exists('runcache_normalize_config_host')) {
    /**
     * Normalizes a host for the config key: port and trailing dots removed.
     * Some clients send FQDN form ('example.com.') or a port (':8080').
     *
     * Must stay in sync with Multisite::normalize_config_key_host() in
     * src/Integrations/Multisite.php.
     *
     * @param string $host Raw host value.
     * @return string Normalized host, possibly empty.
     */
    function runcache_normalize_config_host(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = rtrim($host, '.');

        return runcache_normalize_config_component($host);
    }
}

if (!function_exists('runcache_get_config_candidates')) {
    /**
     * Returns the config map keys to try for the current request, longest first.
     *
     * Must stay in sync with Multisite::get_config_key() in
     * src/Integrations/Multisite.php and runcache_object_cache_config_candidates()
     * in includes/object-cache.php, so all sides yield identical keys.
     *
     * The first candidate present in the sites map wins. A subpage of the
     * main site then falls back to the host row, and a nested site matches
     * its full path before its parent's row.
     *
     * @return array<int, string> Candidate keys, longest path first.
     */
    function runcache_get_config_candidates(): array
    {
        $host = runcache_normalize_config_host(runcache_get_server_var('HTTP_HOST'));

        // An empty host (e.g. under CLI) misses the lookup on purpose, so
        // the per-site fallback then applies.
        if ($host === '') {
            return [];
        }

        if (!(defined('MULTISITE') && MULTISITE && (!defined('SUBDOMAIN_INSTALL') || !SUBDOMAIN_INSTALL))) {
            return [$host];
        }

        // Subdirectory multisite: the URI path narrows the site, most
        // specific path first, and the host row is the last candidate.
        $canonical_path = runcache_get_canonical_path();
        if ($canonical_path === null) {
            // A non-canonical path cannot narrow the site, so only the host row applies.
            return [$host];
        }

        $path = trim($canonical_path, '/');

        $candidates = [];
        $prefix = '';
        foreach (explode('/', $path) as $segment) {
            $segment = runcache_normalize_config_component($segment);
            if ($segment === '') {
                continue;
            }

            $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
            $candidates[] = $host . '/' . $prefix;
        }

        $candidates = array_reverse($candidates);
        $candidates[] = $host;

        return $candidates;
    }
}

// ===== LOAD CONFIGURATION =====

$runcache_loaded = null;

if (is_readable(RUNCACHE_CONFIG_FILE)) {
    $runcache_ob_level = ob_get_level();
    ob_start();
    try {
        // A broken file must not abort the request, so the include is guarded.
        $runcache_loaded = @include RUNCACHE_CONFIG_FILE;
    } catch (\Throwable $runcache_exception) {
        $runcache_loaded = null; // ParseError is catchable (PHP 7+).
    } finally {
        // A corrupted file can emit stray output on include, and only
        // our own buffer may be closed, never an outer one.
        if (ob_get_level() > $runcache_ob_level) {
            ob_end_clean();
        }
    }

    if (!is_array($runcache_loaded)) {
        $runcache_loaded = null;
    }
}

$runcache_site_config = null;
$runcache_matched_site_key = '';
if (is_array($runcache_loaded)
    && isset($runcache_loaded['sites'])
    && is_array($runcache_loaded['sites'])
) {
    foreach (runcache_get_config_candidates() as $runcache_candidate) {
        if (isset($runcache_loaded['sites'][$runcache_candidate])
            && is_array($runcache_loaded['sites'][$runcache_candidate])
        ) {
            $runcache_site_config = $runcache_loaded['sites'][$runcache_candidate];
            $runcache_matched_site_key = $runcache_candidate;
            break;
        }
    }
}

if (is_array($runcache_site_config)) {
    $GLOBALS['runcache_config'] = $runcache_site_config;
} elseif (is_array($runcache_loaded)) {
    // A fresh child site keeps the fail-open semantics of a missing file.
    $GLOBALS['runcache_config'] = array_merge(
        ['cache_enabled' => true, 'page_cache_type' => 'php'],
        $runcache_fallback_rules
    );
} elseif (is_readable(RUNCACHE_CONFIG_FILE)) {
    // A broken config must not cache anything with guessed rules, so this fails closed.
    $GLOBALS['runcache_config'] = array_merge(
        ['cache_enabled' => false, 'page_cache_type' => 'php'],
        $runcache_fallback_rules
    );
} else {
    // The first request runs before the first save, so the defaults fail open.
    $GLOBALS['runcache_config'] = array_merge(
        ['cache_enabled' => true, 'page_cache_type' => 'php'],
        $runcache_fallback_rules
    );
}

// The WooCommerce floor is root-anchored, so a child site needs its own
// path-relative copies. The matched key carries the site path.
if (str_contains($runcache_matched_site_key, '/')
    && is_array($GLOBALS['runcache_hardcoded_exclusions'] ?? null)
    && is_array($GLOBALS['runcache_hardcoded_exclusions']['exclude_url'] ?? null)
) {
    $runcache_site_path = substr($runcache_matched_site_key, (int) strpos($runcache_matched_site_key, '/') + 1);
    foreach (['cart', 'checkout', 'my-account'] as $runcache_woo_endpoint) {
        $GLOBALS['runcache_hardcoded_exclusions']['exclude_url'][] =
            '^/' . $runcache_site_path . '/' . $runcache_woo_endpoint . '(/|$)';
    }
}

// Set HTTP_X_DEVICE from the User-Agent when the cache key uses it
// and no proxy set it.
$extra_var = $GLOBALS['runcache_config']['cache_key_extra_var'] ?? '';
if (!empty($extra_var)
    && str_contains($extra_var, 'HTTP_X_DEVICE')
    && !isset($_SERVER['HTTP_X_DEVICE'])
) {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $_SERVER['HTTP_X_DEVICE'] = preg_match(
        '/Mobile|Android|iPhone|iPod|BlackBerry|Opera Mini|Windows Phone/i',
        $ua
    ) ? 'mobile' : 'desktop';
}
