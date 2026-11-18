<?php

namespace RunCache\Integrations;

use Exception;
use RunCache\Core\Cdn;
use RunCache\Core\Edge;
use RunCache\Core\ObjectCache;
use RunCache\Core\PageCache;
use RunCache\Core\Purger;
use RunCache\Core\Preloader;
use RunCache\Core\Rules;
use RunCache\Core\Setting;
use RunCache\Integrations\Interfaces\CacheProvider;
use RunCache\Support\Logger;
use RunCache\Support\Server;
use RunCache\Support\Validator;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RunCloud integration handler
 */
class Runcloud implements CacheProvider
{
    private string $rc_api_endpoint = RUNCACHE_API_ENDPOINT;
    private Logger $logger;
    private Validator $validator;
    private Setting $setting;
    private array $default_settings = [
        'rcapi_key' => '',
        'rcapi_secret' => '',
        'webapp_id' => '',
        'cache_type' => 'fastcgi'
    ];

    /**
     * @param Logger|null $logger
     * @param Setting|null $setting
     */
    public function __construct(
        ?Logger $logger = null,
        ?Setting $setting = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->validator = new Validator();
        $this->setting = $setting ?? new Setting();
    }

    /**
     * Initialize integration
     *
     * @return void
     */
    public function init(): void
    {
        add_filter('runcache_cache_providers', [$this, 'provider_registration']);
        add_filter('runcache_cache_settings', [$this, 'extend_cache_settings']);

        add_action('runcache_cache_settings_saved', [$this, 'sync_settings']);
        add_action('runcache_cdn_settings_saved', [$this, 'sync_settings']);
        add_action('runcache_edge_settings_saved', [$this, 'sync_settings']);
        add_action('runcache_object_cache_settings_saved', [$this, 'sync_settings']);
        add_action('runcache_rules_updated', [$this, 'sync_settings']);
        add_action('runcache_purger_updated', [$this, 'sync_settings']);
        add_action('runcache_preloader_updated', [$this, 'sync_settings']);

        // Validate cache settings on child sites - pass 2 arguments
        add_filter('runcache_cache_settings_before_save', [$this, 'validate_child_site_cache'], 10, 2);

        $cache = new PageCache();
        if (in_array($cache->get_cache_type(), ['runcloud-native', 'runcloud-srcache'])) {
            add_action('runcache_after_purge_all', [$this, 'purge_all_cache']);
            add_action('runcache_after_purge_post', [$this, 'purge_post_cache'], 10, 2);
            add_action('runcache_after_purge_url', [$this, 'purge_by_url'], 10, 1);
        }
    }

    /**
     * Register as cache provider
     *
     * @param array $providers
     * @return array
     */
    public function provider_registration(array $providers): array
    {
        // Register as nginx-rc for backward compatibility
        $providers['nginx-rc'] = $this;
        // Register new standard cache type names
        $providers['runcloud-native'] = $this;
        $providers['runcloud-srcache'] = $this;

        return $providers;
    }

    /**
     * Extend cache settings with RunCloud configuration
     *
     * @param array $settings
     * @return array
     */
    public function extend_cache_settings(array $settings): array
    {
        $runcloud_settings = $this->get_settings();

        if (is_wp_error($runcloud_settings)) {
            $this->logger->error('Failed to load settings for cache filter', [
                'provider' => 'RunCloud',
                'error' => $runcloud_settings->get_error_message(),
            ]);
            return $settings;
        }

        $settings['runcloud'] = [
            'cache_type' => $runcloud_settings['cache_type'],
            'connected' => !empty($runcloud_settings['rcapi_key']) && !empty($runcloud_settings['rcapi_secret'])
        ];

        return $settings;
    }

    /**
     * Enable RunCloud cache
     *
     * @return bool
     */
    public function enable(): bool
    {
        $this->logger->info('Enable cache', ['provider' => 'RunCloud']);

        // Sync runcloud_settings.cache_type with current page_cache_type
        $cache = new PageCache();
        $page_cache_type = $cache->get_cache_type();

        if (in_array($page_cache_type, ['runcloud-native', 'runcloud-srcache'], true)) {
            // Map: runcloud-native → native, runcloud-srcache → sr
            $cache_type = ($page_cache_type === 'runcloud-srcache') ? 'sr' : 'native';

            $runcloud_settings = $this->get_settings();
            if (!is_wp_error($runcloud_settings)) {
                $runcloud_settings['cache_type'] = $cache_type;
                $this->save_settings($runcloud_settings);
            }
        }

        $this->purge_all_cache();

        do_action('runcache_runcloud_cache_enabled', $this);

        return true;
    }

    /**
     * Disable RunCloud cache
     *
     * @return bool
     */
    public function disable(): bool
    {
        $this->logger->info('Disable cache', ['provider' => 'RunCloud']);

        $this->purge_all_cache();

        // Update child sites to use PHP cache when main site disables RunCloud
        $multisite = new Multisite();
        if ($multisite->is_multisite() && $multisite->is_main_site()) {
            $this->update_child_sites_to_use_php_cache();
        }

        do_action('runcache_runcloud_cache_disabled', $this);

        return true;
    }

    /**
     * Verify RunCloud cache configuration
     *
     * @return bool
     */
    public function verify(): bool
    {
        $cache = new PageCache();
        $response = $cache->request_cache_type();

        // $this->logger->info($response);

        return in_array($response, ['nginx', 'native', 'sr']);
    }

    /**
     * Get cache statistics.
     *
     * Stub: RunCloud native/SRCache are server-level caches and the plugin
     * currently has no way to read their size or page count from WordPress.
     *
     * @return array
     */
    public function get_stats(): array
    {
        return [
            'total_files' => 0,
            'total_size' => 0,
            'total_gzipped_size' => 0,
            'expired_files' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'hit_rate' => 0,
            'compression_ratio' => 0,
            'provider' => 'runcloud',
            'cache_size_label' => __('Served by RunCloud', 'runcache'),
        ];
    }

    /**
     * Get purge endpoints based on cache type.
     *
     * Native RunCloud cache can use either the FastCGI or proxy zone. Both
     * purge locations are generated by RunCloud, so purging both avoids
     * guessing the active backend from the host environment.
     *
     * @param string $cache_type 'native' or 'sr'
     * @return array<int, array{endpoint: string, name: string}>
     */
    private function get_purge_endpoints(string $cache_type): array
    {
        if ($cache_type === 'sr') {
            return [[
                'endpoint' => '/runcache-purge-srcache/',
                'name' => 'SRCache'
            ]];
        }

        return [
            [
                'endpoint' => '/runcache-purge-fastcgi/',
                'name' => 'fastcgi'
            ],
            [
                'endpoint' => '/runcache-purge-proxy/',
                'name' => 'proxy'
            ],
        ];
    }

    /**
     * Make purge request and log results
     *
     * @param string $purge_url The full URL to purge
     * @param string $cache_name The name of the cache type for logging
     * @param array $context Additional context for logging
     * @param string $method HTTP method to use (default: PURGE)
     * @return bool True on success, false on failure
     */
    private function make_purge_request(string $purge_url, string $cache_name, array $context = [], string $method = 'PURGE'): bool
    {
        $server = new Server();

        // Extract original host (domain) for Host header
        $parsed_url = wp_parse_url($purge_url);
        $original_host = $parsed_url['host'] ?? 'localhost';

        // Get internal target host (nginx-rc for Docker, 127.0.0.1 for non-Docker)
        $target_host = $server->get_internal_host();

        // Build internal request URL
        $path = $parsed_url['path'] ?? '/';
        $query = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $last_error = null;
        $last_request_url = null;

        // Try HTTPS directly first so an HTTP-to-HTTPS redirect cannot move
        // the request from the local nginx server to the public hostname.
        foreach (['http', 'https'] as $scheme) {
            $request_url = $scheme . '://' . $target_host . $path . $query;
            $last_request_url = $request_url;

            $response = wp_remote_request($request_url, [
                'method' => $method,
                'timeout' => 30,
                'redirection' => 0,
                'sslverify' => false,
                'headers' => [
                    'Host' => $original_host,
                ],
            ]);

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                continue;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code >= 200 && $status_code < 300 || $status_code === 412) {
                // 412 means the purge key is not present, so the cache is already clear.
                return true;
            }
        }

        $this->logger->error('Failed to purge cache', [
            'provider' => 'RunCloud',
            'cache_name' => $cache_name,
            'error' => $last_error,
            'url' => $purge_url,
            'request_url' => $last_request_url,
        ]);

        return false;
    }

    /**
     * Get RunCloud cache type from settings
     *
     * @return string|null 'native' or 'sr', or null if unable to get
     */
    private function get_cache_type(): ?string
    {
        $runcloud_settings = $this->get_settings();
        if (is_wp_error($runcloud_settings)) {
            return null;
        }

        return $runcloud_settings['cache_type'] ?? 'native';
    }

    /**
     * Purge all RunCloud cache
     *
     * Supports both cache types:
     * - runcloud-native (proxy cache): /runcache-purgeall-proxy
     * - runcloud-srcache (Redis SRCache): /runcache-purgeall-srcache
     * - fastcgi (RunCloud container): /runcache-purgeall-fastcgi
     *
     * @return void
     */
    public function purge_all_cache(): void
    {
        $cache_type = $this->get_cache_type();
        if ($cache_type === null) {
            $this->logger->error('Failed to get cache type', ['provider' => 'RunCloud']);
            return;
        }

        foreach ($this->get_purge_endpoints($cache_type) as $endpoint_info) {
            // Extract endpoint subdirectory (e.g., 'fastcgi', 'srcache', 'proxy').
            $endpoint = trim($endpoint_info['endpoint'], '/');
            $endpoint_subdir = str_replace('runcache-purge-', '', $endpoint);
            $purge_url = home_url('/runcache-purgeall-' . $endpoint_subdir);

            // SRCache purge-all endpoint expects GET; proxy/FastCGI use PURGE.
            $method = ($cache_type === 'sr') ? 'GET' : 'PURGE';

            $this->make_purge_request($purge_url, $endpoint_info['name'], [
                'cache_type' => $cache_type,
            ], $method);
        }
    }

    /**
     * Purge cache for a specific post
     *
     * Supports both cache types:
     * - runcloud-native (proxy cache): /runcache-purge-proxy/{path}
     * - runcloud-srcache (Redis SRCache): /runcache-purge-srcache/{path}
     *
     * @param int $post_id
     * @param string|array $urls
     * @return void
     */
    public function purge_post_cache(int $post_id, string|array $urls): void
    {
        try {
            $normalized_urls = $this->validator->normalize_url_list($urls);

            if (empty($normalized_urls)) {
                $this->logger->info('No URLs to purge for post', [
                    'provider' => 'RunCloud',
                    'post_id' => $post_id,
                ]);

                return;
            }

            $cache_type = $this->get_cache_type();
            if ($cache_type === null) {
                $this->logger->error('Failed to get cache type', [
                    'provider' => 'RunCloud',
                    'post_id' => $post_id,
                ]);
                return;
            }

            $endpoint_infos = $this->get_purge_endpoints($cache_type);
            $purged_count = 0;

            foreach ($normalized_urls as $url) {
                // Parse URL and extract the path
                $parsed_url = wp_parse_url($url);
                $path = isset($parsed_url['path']) ? $parsed_url['path'] : '/';

                // Remove leading/trailing slashes and preserve the root path.
                $path = trim($path, '/');
                $path = $path === '' ? '/' : '/' . $path . '/';

                foreach ($endpoint_infos as $endpoint_info) {
                    // Build the purge URL using WordPress site domain.
                    $purge_url = home_url(rtrim($endpoint_info['endpoint'], '/') . $path);

                    // SRCache and proxy endpoints expect GET; FastCGI uses PURGE.
                    $method = ($cache_type === 'sr' || $endpoint_info['name'] === 'proxy') ? 'GET' : 'PURGE';

                    $success = $this->make_purge_request($purge_url, $endpoint_info['name'], [
                        'post_id' => $post_id,
                        'url' => $url,
                        'cache_type' => $cache_type,
                    ], $method);

                    if ($success) {
                        $purged_count++;
                    }
                }
            }

            if ($purged_count > 0) {
                $this->logger->info('Purged cache for post', [
                    'provider' => 'RunCloud',
                    'cache_name' => 'RunCloud native endpoints',
                    'post_id' => $post_id,
                    'purged_count' => $purged_count,
                    'total_requests' => count($normalized_urls) * count($endpoint_infos),
                ]);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to purge post cache', ['provider' => 'RunCloud', 'error' => $e->getMessage()]);
        }
    }

    /**
     * Purge cache for a specific URL
     *
     * Supports both cache types:
     * - runcloud-native (proxy cache): /runcache-purge-proxy/{path}
     * - runcloud-srcache (Redis SRCache): /runcache-purge-srcache/{path}
     *
     * @param string $url
     * @return void
     */
    public function purge_by_url(string $url): void
    {
        try {
            if (empty($url)) {
                $this->logger->info('No URL to purge', ['provider' => 'RunCloud']);
                return;
            }

            $cache_type = $this->get_cache_type();
            if ($cache_type === null) {
                $this->logger->error('Failed to get cache type', ['provider' => 'RunCloud']);
                return;
            }

            // Parse URL and extract the path
            $parsed_url = wp_parse_url($url);
            $path = isset($parsed_url['path']) ? $parsed_url['path'] : '/';

            // Remove leading/trailing slashes and preserve the root path.
            $path = trim($path, '/');
            $path = $path === '' ? '/' : '/' . $path . '/';

            foreach ($this->get_purge_endpoints($cache_type) as $endpoint_info) {
                // Build the purge URL using WordPress site domain.
                $purge_url = home_url(rtrim($endpoint_info['endpoint'], '/') . $path);

                // SRCache and proxy endpoints expect GET; FastCGI uses PURGE.
                $method = ($cache_type === 'sr' || $endpoint_info['name'] === 'proxy') ? 'GET' : 'PURGE';

                $this->make_purge_request($purge_url, $endpoint_info['name'], [
                    'url' => $url,
                    'cache_type' => $cache_type,
                ], $method);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to purge cache for URL', ['provider' => 'RunCloud', 'error' => $e->getMessage()]);
        }
    }

    /**
     * Save RunCloud settings
     *
     * @param array $settings
     * @return bool|WP_Error
     */
    public function save_settings(array $settings): bool|WP_Error
    {
        try {
            $settings = wp_parse_args($settings, $this->get_default_settings());

            $this->setting->set('runcloud', $settings, (new Multisite())->is_multisite());
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to save settings', ['provider' => 'RunCloud', 'error' => $e->getMessage()]);
            return new WP_Error('save_settings_failed', $e->getMessage());
        }
    }

    /**
     * Get RunCloud settings
     *
     * @return array|WP_Error
     */
    public function get_settings(): array|WP_Error
    {
        try {
            $saved_settings = $this->setting->get('runcloud', null, true);
            return wp_parse_args($saved_settings, $this->get_default_settings());
        } catch (Exception $e) {
            $this->logger->error('Failed to get settings', ['provider' => 'RunCloud', 'error' => $e->getMessage()]);
            return new WP_Error('get_settings_failed', $e->getMessage());
        }
    }

    /**
     * Save RunCloud info from CLI command
     *
     * @param string $cache_type
     * @param string $rcapi_key
     * @param string $rcapi_secret
     * @param string $webapp_id
     * @return bool|WP_Error
     */
    public function save_runcloud_info(string $cache_type, string $rcapi_key, string $rcapi_secret, string $webapp_id): bool|WP_Error
    {
        try {
            $existing_settings = $this->get_settings();
            $has_existing_settings = !is_wp_error($existing_settings)
                && !empty($existing_settings['rcapi_key'])
                && !empty($existing_settings['rcapi_secret'])
                && !empty($existing_settings['webapp_id']);

            if (!in_array($cache_type, ['native', 'sr'], true)) {
                return new WP_Error('invalid_cache_type', 'Invalid cache type. Valid options are: native, sr, you use ' . $cache_type);
            }

            if (!$has_existing_settings && (empty($rcapi_key) || empty($rcapi_secret) || empty($webapp_id))) {
                return new WP_Error('missing_credentials', 'RunCloud API key, API secret, and WebApp ID are required for first time setup');
            }

            if (is_wp_error($existing_settings)) {
                $existing_settings = [];
            }

            $new_settings = [
                'cache_type' => $cache_type,
            ];

            if (!empty($rcapi_key)) {
                $new_settings['rcapi_key'] = $rcapi_key;
            }

            if (!empty($rcapi_secret)) {
                $new_settings['rcapi_secret'] = $rcapi_secret;
            }

            if (!empty($webapp_id)) {
                $new_settings['webapp_id'] = $webapp_id;
            }

            return $this->save_settings(array_merge($existing_settings, $new_settings));
        } catch (Exception $e) {
            $this->logger->error('Failed to save info', ['provider' => 'RunCloud', 'error' => $e->getMessage()]);
            return new WP_Error('save_info_failed', $e->getMessage());
        }
    }

    /**
     * Get default RunCloud settings
     *
     * @return array
     */
    private function get_default_settings(): array
    {
        return $this->default_settings;
    }

    /**
     * Make authenticated request to RunCloud API
     *
     * @param string $method
     * @param string $endpoint
     * @param array $body
     * @return array|WP_Error
     */
    private function make_api_request(string $method, string $endpoint, array $body = []): array|WP_Error
    {
        try {
            $settings = $this->get_settings();

            $body['runcloud_settings'] = $settings;

            if (empty($settings)) {
                throw new Exception('RunCloud configuration is missing. Please run: wp runcache enable runcloud <rcapi_key> <rcapi_secret> <webapp_id>');
            }

            $required_fields = ['rcapi_key', 'rcapi_secret', 'webapp_id'];
            foreach ($required_fields as $field) {
                if (empty($settings[$field])) {
                    throw new Exception("RunCloud {$field} is missing. Please run: wp runcache enable runcloud <rcapi_key> <rcapi_secret> <webapp_id>");
                }
            }

            $authorization = base64_encode($settings['rcapi_key'] . ':' . $settings['rcapi_secret']);

            $args = [
                'timeout' => 30,
                'method' => $method,
                'sslverify' => true,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Basic ' . $authorization,
                ],
                'body' => wp_json_encode($body),
            ];

            $endpoint = sprintf(
                '%s/api/runcache/webapps/%s/%s',
                $this->rc_api_endpoint,
                $settings['webapp_id'],
                $endpoint
            );

            $response = wp_remote_request($endpoint, $args);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $status_code = wp_remote_retrieve_response_code($response);
            if ($status_code !== 200) {
                $body = wp_remote_retrieve_body($response);
                $error = json_decode($body, true);
                throw new Exception($error['message'] ?? 'Unknown error occurred while making API request');
            }

            return json_decode(wp_remote_retrieve_body($response), true);
        } catch (Exception $e) {
            return new WP_Error('api_request_failed', $e->getMessage());
        }
    }

    /**
     * Validate child site cache settings
     * Ensure child sites cannot override main site's RunCloud cache type
     *
     * @param array $settings Settings to be saved
     * @param array $previous_settings Previous settings
     * @return array Modified settings array
     */
    public function validate_child_site_cache(array $settings, array $previous_settings): array
    {
        // Only validate on multisite child sites
        if (!function_exists('is_multisite') || !is_multisite()) {
            return $settings;
        }

        $multisite = new Multisite();
        if ($multisite->is_main_site()) {
            return $settings;
        }

        // Check if trying to set a runcloud cache type
        $page_cache_type = $settings['page_cache_type'] ?? '';
        if (!in_array($page_cache_type, ['runcloud-native', 'runcloud-srcache'], true)) {
            return $settings;
        }

        // Get main site's settings using existing get() method with defaults
        $main_site_settings = $this->setting->get(
            'page_cache',
            [
                'page_cache_type' => 'php',
                'enable' => false,
            ],
            true  // true = get from main site
        );
        $main_site_page_cache_type = $main_site_settings['page_cache_type'] ?? '';

        // If main site doesn't use runcloud cache, ignore the request (keep previous settings)
        if (!in_array($main_site_page_cache_type, ['runcloud-native', 'runcloud-srcache'], true)) {
            $settings['page_cache_type'] = $previous_settings['page_cache_type'];
            $this->logger->warning('Child site attempted to use cache but main site does not use it. Ignoring request.', [
                'provider' => 'RunCloud',
                'child_site' => get_current_blog_id(),
                'requested_cache_type' => $page_cache_type,
            ]);
            return $settings;
        }

        // If cache types don't match, force child to use main site's cache type
        if ($page_cache_type !== $main_site_page_cache_type) {
            $settings['page_cache_type'] = $main_site_page_cache_type;
            $this->logger->warning('Child site attempted to use different cache type than main site. Forcing main site cache type.', [
                'provider' => 'RunCloud',
                'child_site' => get_current_blog_id(),
                'requested_cache_type' => $page_cache_type,
                'forced_cache_type' => $main_site_page_cache_type,
                'main_site_cache_type' => $main_site_page_cache_type,
            ]);
        }

        return $settings;
    }

    /**
     * Update all child sites using RunCloud cache to use PHP cache
     *
     * When the main site disables RunCloud cache, all child sites that are
     * currently using runcloud-native or runcloud-srcache will be automatically
     * updated to use PHP cache instead.
     *
     * @return void
     */
    private function update_child_sites_to_use_php_cache(): void
    {
        $site_ids = get_sites(['fields' => 'ids']);

        if (empty($site_ids)) {
            $this->logger->info('No sites found in multisite network');
            return;
        }

        $main_site_id = function_exists('get_main_site_id') ? get_main_site_id() : 1;

        foreach ($site_ids as $site_id) {
            // Skip the main site
            if ($site_id === $main_site_id) {
                continue;
            }

            $switched = false;

            try {
                // Switch to child site context
                switch_to_blog($site_id);
                $switched = true;

                // Get current page cache settings
                $page_cache = new PageCache();
                $current_settings = $page_cache->get_settings();
                $current_cache_type = $current_settings['page_cache_type'] ?? '';

                // Only update if using RunCloud cache
                if (in_array($current_cache_type, ['runcloud-native', 'runcloud-srcache'], true)) {
                    $page_cache->save_settings([
                        'enable' => true,
                        'page_cache_type' => 'php',
                    ]);
                }
            } catch (Exception $e) {
                $this->logger->error('Exception while updating child site cache', [
                    'provider' => 'RunCloud',
                    'site_id' => $site_id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Always restore to main site context after try-catch
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    /**
     * Push settings to RunCloud Hub. Includes the edge settings, so the
     * dashboard backup carries the Cloudflare credentials for future migrations.
     *
     * @return bool|WP_Error
     */
    public function sync_settings(): bool|WP_Error
    {
        // Don't sync if this is a child site in multisite
        if (function_exists('is_multisite') && is_multisite()) {
            $multisite = new Multisite();
            if ($multisite->is_main_site() === false) {
                return true; // Return early, no sync needed for child sites
            }
        }

        $payload = [
            'cache_settings' => (new PageCache())->get_settings(),
            'cdn_settings' => (new Cdn())->get_settings(),
            'object_cache_settings' => (new ObjectCache())->get_settings(),
            'edge_settings' => (new Edge())->get_settings(),
            'rules_settings' => $this->get_rules_settings_for_sync(),
            'purger_settings' => (new Purger())->get_settings(),
            'preloader_settings' => (new Preloader())->get_settings()
        ];

        $response = $this->make_api_request('POST', 'sync-settings', $payload);

        if (is_wp_error($response)) {
            return $response;
        }

        return true;
    }

    /**
     * Build the rules_settings payload sent to RunCloud.
     *
     * The dashboard (configurable) rules are merged with the hardcoded
     * (mandatory) safety exclusions so the server receives the whole set of
     * exclusion rules. Hardcoded lists are flattened into the existing
     * dashboard `_mch` keys, mirroring how the PHP drop-in keeps a single flat
     * matching surface. No new keys are introduced.
     *
     * @return array<string, mixed>
     */
    private function get_rules_settings_for_sync(): array
    {
        $rules = (new Rules())->get_settings();
        $hardcoded = $this->get_hardcoded_exclusions();

        // Map each hardcoded feature-family to the dashboard `_mch` key.
        $hardcoded_to_mch = [
            'exclude_url'    => 'exclude_url_mch',
            'exclude_cookie' => 'exclude_cookie_mch',
            'exclude_query'  => 'exclude_query_mch',
        ];

        foreach ($hardcoded_to_mch as $family => $mch_key) {
            $existing = $rules[$mch_key] ?? [];
            $extra = (array) ($hardcoded[$family] ?? []);

            // Lists may be stored as arrays or newline-separated strings.
            if (is_string($existing)) {
                $list = array_filter(array_map('trim', preg_split('/\r?\n/', $existing) ?: []));
            } else {
                $list = is_array($existing) ? $existing : [];
            }

            $rules[$mch_key] = array_values(array_unique(array_merge($list, $extra)));
        }

        // The dashboard and hardcoded `_mch` lists are all rendered as PCRE by
        // the server. Any entry that uses a glob wildcard (`*` or `?`) must be
        // converted to a valid PCRE, or it will break `nginx -t`. Entry values
        // that are already valid regexes are left untouched.
        foreach ($rules as $key => $value) {
            if (!is_string($key) || !str_ends_with($key, '_mch')) {
                continue;
            }

            if (is_array($value)) {
                $rules[$key] = array_values(array_map([$this, 'glob_to_regex_if_needed'], $value));
            } elseif (is_string($value) && $value !== '') {
                $lines = array_filter(array_map('trim', preg_split('/\r?\n/', $value) ?: []));
                $rules[$key] = implode("\n", array_map([$this, 'glob_to_regex_if_needed'], $lines));
            }
        }

        return $rules;
    }

    /**
     * Convert a pattern to valid PCRE only when it uses glob wildcards.
     *
     * `*` and `?` act as glob wildcards in the dashboard and hardcoded lists,
     * so they are converted. Every other value is already a valid PCRE
     * (for example `wordpress_sec_[a-f0-9]+`) and is returned unchanged.
     *
     * @param string|int $pattern
     * @return string
     */
    private function glob_to_regex_if_needed($pattern): string
    {
        $pattern = (string) $pattern;

        if (strpbrk($pattern, '*?') === false) {
            return $pattern;
        }

        return $this->glob_to_regex($pattern);
    }

    /**
     * Convert a glob pattern to a valid PCRE pattern.
     *
     * `*` -> `.*` (any chars), `?` -> `.` (single char); all other regex
     * metacharacters are escaped. So glob `*sitemap*.xml` -> regex
     * `.*sitemap.*\.xml`.
     *
     * @param string|int $pattern
     * @return string
     */
    private function glob_to_regex($pattern): string
    {
        return str_replace(['\*', '\?'], ['.*', '.'], preg_quote((string) $pattern));
    }

    /**
     * Get the mandatory (hardcoded) safety exclusion rules.
     *
     * The defaults file is structured under two top-level keys, 'hardcoded'
     * and 'rules'; the 'hardcoded' block uses the same feature-family child
     * keys as the dashboard rules ('exclude_url', 'exclude_cookie',
     * 'exclude_query').
     *
     * @return array<string, list<string>>
     */
    private function get_hardcoded_exclusions(): array
    {
        $defaults_file = defined('RUNCACHE_PLUGIN_DIR')
            ? RUNCACHE_PLUGIN_DIR . 'includes/runcache-rule-defaults.php'
            : dirname(__DIR__, 2) . '/includes/runcache-rule-defaults.php';
        $defaults = is_readable($defaults_file) ? require $defaults_file : [];

        if (!is_array($defaults)) {
            return [];
        }

        return is_array($defaults['hardcoded'] ?? null) ? $defaults['hardcoded'] : [];
    }

    /**
     * Import settings from JSON data
     *
     * Accepts JSON data containing cache_settings, cdn_settings, and object_cache_settings,
     * and saves them to the local database.
     *
     * @param array $data Array with cache_settings, cdn_settings, object_cache_settings
     * @return array|WP_Error Array with 'saved' and 'messages' on success, WP_Error on failure
     */
    public function import_settings_cli(array $data): array|WP_Error
    {
        try {
            $success = true;
            $messages = [];

            // Save page cache settings
            if (isset($data['cache_settings']) && is_array($data['cache_settings'])) {
                $page_cache = new PageCache();
                if ($page_cache->save_settings($data['cache_settings'])) {
                    $messages[] = 'Page cache settings saved';
                } else {
                    $success = false;
                    $messages[] = 'Failed to save page cache settings';
                }
            }

            // Save CDN settings
            if (isset($data['cdn_settings']) && is_array($data['cdn_settings'])) {
                $cdn = new Cdn();
                if ($cdn->save_settings($data['cdn_settings'])) {
                    $messages[] = 'CDN settings saved';
                } else {
                    $success = false;
                    $messages[] = 'Failed to save CDN settings';
                }
            }

            // Save CDN Edge Cache settings
            if (isset($data['edge_settings']) && is_array($data['edge_settings'])) {
                $edge = new \RunCache\Core\Edge();
                if ($edge->save_settings($data['edge_settings'])) {
                    $messages[] = 'CDN Edge Cache settings saved';
                } else {
                    $success = false;
                    $messages[] = 'Failed to save CDN Edge Cache settings';
                }
            }

            // Save object cache settings
            if (isset($data['object_cache_settings']) && is_array($data['object_cache_settings'])) {
                $object_cache = new ObjectCache();
                if ($object_cache->save_settings($data['object_cache_settings'])) {
                    $messages[] = 'Object cache settings saved';
                } else {
                    $success = false;
                    $messages[] = 'Failed to save object cache settings';
                }
            }

            // NEW: Handle runcloud_settings for nginx cache type
            if (isset($data['runcloud_settings']['cache_type'])) {
                $runcloud_settings = $this->get_settings();
                $runcloud_settings['cache_type'] = $data['runcloud_settings']['cache_type'];
                if ($this->save_settings($runcloud_settings)) {
                    $messages[] = 'RunCloud settings saved';
                } else {
                    $success = false;
                    $messages[] = 'Failed to save RunCloud settings';
                }
            }

            if (empty($messages)) {
                return new WP_Error('no_settings', 'No settings provided to import');
            }

            return [
                'saved' => $success,
                'messages' => $messages
            ];
        } catch (Exception $e) {
            return new WP_Error('import_failed', $e->getMessage());
        }
    }

    /**
     * Restore RunCloud Hub config backup
     *
     * @return array|WP_Error Array with 'restored_settings', 'used_keys', 'unused_keys' on success, WP_Error on failure
     */
    public function restore_runcloudhub_config(): array|WP_Error
    {
        try {
            $backup = $this->get_backup_data();
            if (is_wp_error($backup)) {
                return $backup;
            }

            $restored_settings = [];
            $used_keys = [];

            $purger_result = $this->restore_purger_settings($backup);
            if ($purger_result !== null && $purger_result['success']) {
                $restored_settings['purger'] = $purger_result['settings'];
                $used_keys = array_merge($used_keys, array_keys($purger_result['settings']));
            }

            $preloader_result = $this->restore_preloader_settings($backup);
            if ($preloader_result !== null && $preloader_result['success']) {
                $restored_settings['preloader'] = $preloader_result['settings'];
                $used_keys = array_merge($used_keys, array_keys($preloader_result['settings']));
            }

            $rules_result = $this->restore_rules_settings($backup);
            if ($rules_result !== null && $rules_result['success']) {
                $restored_settings['rules'] = $rules_result['settings'];
                $used_keys = array_merge($used_keys, array_keys($rules_result['settings']));
            }

            $object_cache_result = $this->restore_object_cache_settings($backup);
            if (null !== $object_cache_result) {
                $used_keys[] = 'redis_cache_onn';
                if ($object_cache_result['success']) {
                    $restored_settings['object_cache'] = $object_cache_result['settings'];
                }
            }

            $unused_keys = array_diff(array_keys($backup), $used_keys);

            if (empty($restored_settings) && empty($backup)) {
                return new WP_Error('restore_failed', 'No settings were restored from backup');
            }

            return [
                'restored_settings' => $restored_settings,
                'used_keys' => $used_keys,
                'unused_keys' => $unused_keys
            ];
        } catch (Exception $e) {
            return new WP_Error('restore_exception', $e->getMessage());
        }
    }

    /**
     * Get and validate backup data
     *
     * @return array|WP_Error
     */
    private function get_backup_data(): array|WP_Error
    {
        $backup = get_option('runcloud_hub_config_backup');

        if (false === $backup) {
            return new WP_Error('no_backup', 'No backup configuration found');
        }

        if (is_string($backup)) {
            $decoded = json_decode($backup, true);
            if (null === $decoded) {
                return new WP_Error('invalid_backup', 'Invalid JSON backup data');
            }
            return $decoded;
        } elseif (is_array($backup)) {
            return $backup;
        } else {
            return new WP_Error('invalid_backup_format', 'Invalid backup data format');
        }
    }

    /**
     * Get and validate the old plugin's Cloudflare option. It stores the zone
     * API response and holds the zone ID, which the config backup never has.
     *
     * @return array|WP_Error
     */
    private function get_cloudflare_backup_data(): array|WP_Error
    {
        $backup = get_option('runcloud_hub_cloudflare');

        if (false === $backup) {
            return new WP_Error('no_backup', 'No Cloudflare backup found');
        }

        if (is_string($backup)) {
            $decoded = json_decode($backup, true);
            if (null === $decoded) {
                return new WP_Error('invalid_backup', 'Invalid JSON Cloudflare backup data');
            }
            return $decoded;
        } elseif (is_array($backup)) {
            return $backup;
        } else {
            return new WP_Error('invalid_backup_format', 'Invalid Cloudflare backup data format');
        }
    }

    /**
     * Restore Cloudflare settings from the RunCloud Hub backups.
     * The probe picks the provider; every enable is confirmed remotely and reverted on failure.
     *
     * @param array $options {
     *     Options map.
     *
     *     @type bool   $no_enable      Opt out of auto-enable. Default false.
     *     @type bool   $re_detect_zone Re-detect the zone via API when zoneid is missing. Default false.
     *     @type string $cf_provider    'auto' | 'cloudflare' | 'cloudflare-apo'. Default 'auto'.
     *     @type bool   $keep_page_rule Skip the confirmation-gated page-rule cleanup. Default false.
     * }
     * @return array|WP_Error Keys: found, provider, rule_mode, saved, enabled, page_rule_state, status_detail, warnings.
     */
    public function restore_cloudflare_config(array $options = []): array|WP_Error
    {
        try {
            $options = wp_parse_args($options, [
                'no_enable' => false,
                're_detect_zone' => false,
                'cf_provider' => 'auto',
                'keep_page_rule' => false,
            ]);

            $warnings = [];

            // 1. Load both sources. A missing source is not an error.
            // A corrupted source gives a warning and a skip.
            $config_result = $this->get_backup_data();
            if (is_wp_error($config_result)) {
                if ($config_result->get_error_code() !== 'no_backup') {
                    $warnings[] = 'RunCloud Hub config backup is corrupted (unreadable JSON); Cloudflare data skipped.';
                }
                $config = [];
            } else {
                $config = $config_result;
            }

            $zone_result = $this->get_cloudflare_backup_data();
            if (is_wp_error($zone_result)) {
                if ($zone_result->get_error_code() !== 'no_backup') {
                    $warnings[] = 'RunCloud Hub Cloudflare backup is corrupted (unreadable JSON); zone data skipped.';
                }
                $zone = [];
            } else {
                $zone = $zone_result;
            }

            // 2. Detect Cloudflare data. No API key => "not found"; caller skips.
            $raw_key = isset($config['cloudflare_apikey']) && is_string($config['cloudflare_apikey'])
                ? trim($config['cloudflare_apikey'])
                : '';
            if ('' === $raw_key) {
                return ['found' => false];
            }

            // 3. Normalize and classify.
            $token = (new Cloudflare())->normalize_api_token($raw_key);
            $email = isset($config['cloudflare_email']) && is_string($config['cloudflare_email'])
                ? sanitize_email($config['cloudflare_email'])
                : '';
            $auth_type = $this->classify_cf_auth_type($raw_key);
            $zone_id = isset($zone['zoneid']) && is_string($zone['zoneid'])
                ? sanitize_text_field($zone['zoneid'])
                : '';
            $was_onn = (int) ($config['cloudflare_onn'] ?? 0) === 1;

            if (!empty($config['cloudflare_purgeall_onn']) || !empty($config['cloudflare_purgeurl_onn'])) {
                $warnings[] = 'Old purgeall/purgeurl toggles have no equivalent. Purge sync is always on when enabled.';
            }

            $report = [
                'found' => true,
                'provider' => null,
                'rule_mode' => null,
                'saved' => false,
                'enabled' => false,
                'page_rule_state' => 'none',
                'status_detail' => '',
                'warnings' => $warnings,
            ];

            // 4. Gate: a Global API Key without an email cannot work. Report, do not save.
            if ('key' === $auth_type && '' === $email) {
                $report['warnings'][] = 'Global API Key requires an account email. Nothing was saved.';
                $report['status_detail'] = 'Global API Key requires account email; nothing saved';
                return $report;
            }

            $credentials = [
                'api_token' => $token,
                'zone_id' => $zone_id,
                'auth_type' => $auth_type,
                'account_email' => $email,
            ];

            // 5. Optional zone re-detection when the backup has no zone ID.
            if ('' === $zone_id) {
                if ($options['re_detect_zone']) {
                    try {
                        $detector = new Cloudflare();
                        $detected = $detector->detect_zone_for_settings($credentials);
                        $zone_id = sanitize_text_field( (string) ($detected['zone_id'] ?? ''));
                    } catch (Exception $exception) {
                        $report['warnings'][] = 'Zone detection failed: ' . $exception->getMessage();
                    }
                } else {
                    $report['warnings'][] = 'Zone ID missing from backup. Re-run with --re-detect-zone to detect it via the API.';
                }

                if ('' === $zone_id) {
                    $report['saved'] = $this->save_cloudflare_inactive(Edge::CLOUDFLARE, $credentials);
                    $report['status_detail'] = 'zone ID missing from backup';
                    return $report;
                }

                $credentials['zone_id'] = $zone_id;
            }

            // 6. Verify against the CURRENT site domain. GET zone + domain match.
            $cloudflare = new Cloudflare();
            $verification = $cloudflare->with_credentials($credentials, fn () => $cloudflare->verify());
            $verified = (bool) ($verification['verified'] ?? false);
            $zone_status = strtolower(trim( (string) ($verification['data']['status'] ?? '')));

            if (!$verified) {
                $detail = trim( (string) ($verification['message'] ?? ''));
            $detail = '' !== $detail ? $detail : 'zone verification failed';
                $report['warnings'][] = 'Zone verification failed: ' . $detail;
                $report['saved'] = $this->save_cloudflare_inactive(Edge::CLOUDFLARE, $credentials);
                $report['status_detail'] = 'zone verification failed: ' . $detail;
                return $report;
            }

            // 6b. v4.1: auto-enable additionally requires status = 'active'.
            if ('active' !== $zone_status) {
                $shown = '' !== $zone_status ? $zone_status : 'unknown';
                $report['warnings'][] = sprintf('Zone status is "%s" (DNS not pointed yet); enable manually once active.', $shown);
                $report['saved'] = $this->save_cloudflare_inactive(Edge::CLOUDFLARE, $credentials);
                $report['status_detail'] = sprintf('zone status is "%s" (DNS not pointed); enable manually once active', $shown);
                return $report;
            }

            // 7. Pick the provider. The probe is read-only (one GET) and runs
            // with the migrated credentials.
            $apo = new CloudflareApo();
            $state = $apo->with_credentials($credentials, fn () => $apo->probe_apo_state());
            $pick = $this->detect_cf_provider($state);

            $provider = $pick['provider'];
            $decisive = $pick['decisive'];
            $apo_summary = $pick['apo'];

            $forced = in_array($options['cf_provider'], [Edge::CLOUDFLARE, Edge::CLOUDFLARE_APO], true);
            if ($forced) {
                if ($options['cf_provider'] !== $provider) {
                    $report['warnings'][] = sprintf(
                        '--cf-provider=%1$s overrides the auto-detected provider (%2$s).',
                        $options['cf_provider'],
                        $provider
                    );
                }
                $provider = $options['cf_provider'];
                $decisive = true;
            }

            $report['provider'] = $provider;

            // APO pre-checks: the AJAX admin path validates editable + proxied
            // DNS; save_provider() does not, so the migration must.
            $site_proxied = null;
            if (Edge::CLOUDFLARE_APO === $provider) {
                $site_proxied = $apo->with_credentials($credentials, fn () => $apo->is_site_proxied());
            }

            // 8. Enable decision: restore what the admin had, never force on.
            $want_enable = !$options['no_enable']
                && $was_onn
                && $verified
                && ('active' === $zone_status)
                && $decisive
                && (Edge::CLOUDFLARE === $provider || (true === $apo_summary['editable'] && true === $site_proxied));

            // 9. Save, activate, confirm, classify, adopt or self-heal.
            $edge = new Edge();
            $provider_settings = $credentials;
            $provider_settings['rule_mode'] = Cloudflare::RULE_MODE_CACHE_RULES;
            if (Edge::CLOUDFLARE_APO === $provider) {
                $provider_settings['apo_enabled'] = false;
                unset($provider_settings['rule_mode']);
            }

            $saved = $edge->save_provider($provider, $provider_settings, $want_enable);
            $report['saved'] = $saved;

            if ($want_enable && $saved) {
                $provider_instance = Edge::CLOUDFLARE_APO === $provider ? new CloudflareApo() : new Cloudflare();
                $remote_confirmed = $provider_instance->is_remote_active();

                if (Edge::CLOUDFLARE_APO === $provider) {
                    if ($remote_confirmed) {
                        $report['enabled'] = true;
                        $report['status_detail'] = 'APO active on zone (remote confirmed)';
                    } else {
                        // Self-heal: disable() first, then save inactive.
                        $provider_instance->disable();
                        $edge->save_provider($provider, $provider_settings, false);
                        $report['warnings'][] = 'APO enable failed; reverted to inactive. Check debug.log for the Cloudflare error.';
                        $report['status_detail'] = 'enable failed; reverted to inactive';
                    }
                } elseif ($remote_confirmed) {
                    $report['enabled'] = true;
                    $report['rule_mode'] = Cloudflare::RULE_MODE_CACHE_RULES;

                    // Confirmation-gated cleanup. Delete the old page rules only
                    // after the cache rule is confirmed active on the zone.
                    if ($options['keep_page_rule']) {
                        $report['page_rule_state'] = 'kept_inactive';
                        $report['warnings'][] = 'Old page rule kept (--keep-page-rule); it still caches alongside the new cache rule.';
                        $report['status_detail'] = 'cache rule installed (remote confirmed); old page rule kept';
                    } elseif ($provider_instance->delete_managed_page_rules()) {
                        $report['page_rule_state'] = 'removed';
                        $report['status_detail'] = 'cache rule installed (remote confirmed)';
                    } else {
                        $report['page_rule_state'] = 'unknown';
                        $report['warnings'][] = 'Failed to delete the old Cloudflare page rules; remove them manually if needed. The new cache rule is active.';
                        $report['status_detail'] = 'cache rule installed (remote confirmed); page-rule cleanup failed';
                    }
                } else {
                    // Enable failed. Look for an adoptable page rule BEFORE the
                    // rollback: disable() needs the same Cache Rules permission
                    // that just failed. The check is read-only.
                    $has_page_rule = (new Cloudflare())->managed_page_rule_exists();

                    if ($has_page_rule) {
                        // Adoption branch: do NOT call disable() here — its
                        // ruleset PUT needs the same permission that failed.
                        $adopted = $this->adopt_page_rule_mode($edge, $provider_settings, $report['warnings']);
                        $report['enabled'] = $adopted['enabled'];
                        $report['rule_mode'] = $adopted['rule_mode'];
                        $report['page_rule_state'] = $adopted['page_rule_state'];
                        $report['status_detail'] = $adopted['status_detail'];
                    } else {
                        // Self-heal: disable() first, then save inactive.
                        $provider_instance->disable();
                        $edge->save_provider($provider, $provider_settings, false);
                        $report['warnings'][] = 'Enable failed; reverted to inactive. Check debug.log for the Cloudflare error.';
                        $report['status_detail'] = 'enable failed; reverted to inactive';
                    }
                }
            } elseif ($want_enable && !$saved) {
                $report['warnings'][] = 'Failed to save the Cloudflare edge settings.';
                $report['status_detail'] = 'settings save failed';
            } elseif (!$want_enable) {
                if (!$was_onn) {
                    $report['status_detail'] = 'site had integration off in RunCloud Hub';
                } elseif ($options['no_enable']) {
                    $report['status_detail'] = 'saved inactive (--no-enable-cloudflare)';
                } elseif (Edge::CLOUDFLARE_APO === $provider && (true !== $apo_summary['editable'] || true !== $site_proxied)) {
                    $report['status_detail'] = 'APO not editable / site not proxied in Cloudflare';
                } elseif (!$decisive) {
                    $report['status_detail'] = 'APO state unknown; enable manually after review';
                } else {
                    $report['status_detail'] = 'saved (inactive)';
                }
            }

            return $report;
        } catch (Exception $exception) {
            return new WP_Error('cf_restore_failed', $exception->getMessage());
        }
    }

    /**
     * Pick the edge provider from the live APO probe state. Read-only.
     * Default is always 'cloudflare' (cache rules) when the state is unknown.
     *
     * @param array|WP_Error $state Result of CloudflareApo::probe_apo_state().
     * @return array{provider: string, reason: string, decisive: bool, apo: array}
     */
    private function detect_cf_provider(array|WP_Error $state): array
    {
        $apo_summary = [
            'probed' => true,
            'enabled' => null,
            'editable' => null,
            'hostname_match' => null,
            'proxied' => null,
        ];

        if (is_wp_error($state)) {
            $message = $state->get_error_message();

            if (stripos($message, 'not entitled') !== false || stripos($message, 'not_entitled') !== false) {
                return ['provider' => Edge::CLOUDFLARE, 'reason' => 'no_apo_subscription', 'decisive' => true, 'apo' => $apo_summary];
            }

            return ['provider' => Edge::CLOUDFLARE, 'reason' => 'apo_state_unknown', 'decisive' => false, 'apo' => $apo_summary];
        }

        // Hostname match includes the www/apex pair, matching
        // CloudflareApo::get_site_hostnames().
        $site_host = strtolower( (string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $www_pair = str_starts_with($site_host, 'www.') ? substr($site_host, 4) : 'www.' . $site_host;

        $hostnames = is_array($state['hostnames'] ?? null) ? $state['hostnames'] : [];
        $host_match = in_array($site_host, $hostnames, true)
            || in_array($www_pair, $hostnames, true);

        $apo_summary['enabled'] = (bool) ($state['enabled'] ?? false);
        $apo_summary['editable'] = (bool) ($state['editable'] ?? false);
        $apo_summary['hostname_match'] = $host_match;

        if (!empty($state['enabled']) && $host_match) {
            return ['provider' => Edge::CLOUDFLARE_APO, 'reason' => 'apo_active_for_site', 'decisive' => true, 'apo' => $apo_summary];
        }

        return [
            'provider' => Edge::CLOUDFLARE,
            'reason' => !empty($state['enabled']) ? 'apo_active_other_hostname' : 'apo_disabled',
            'decisive' => true,
            'apo' => $apo_summary,
        ];
    }

    /**
     * Adoption branch (§5.6). The cache rule install failed, but a legacy page
     * rule exists on the zone. Switch to page_rule mode; purge sync stays active.
     *
     * @param Edge  $edge Edge provider instance.
     * @param array $provider_settings Cloudflare provider settings (rule_mode = cache_rules).
     * @param array $warnings Warnings list (passed by reference).
     * @return array{enabled: bool, rule_mode: string|null, page_rule_state: string, status_detail: string}
     */
    private function adopt_page_rule_mode(Edge $edge, array $provider_settings, array &$warnings): array
    {
        $adopted_settings = array_merge($provider_settings, ['rule_mode' => Cloudflare::RULE_MODE_PAGE_RULE]);

        // Adopt: the page rule is the active mechanism; save ACTIVE in
        // page_rule mode so purge sync keeps working.
        $edge->save_provider(Edge::CLOUDFLARE, $adopted_settings, true);

        if ((new Cloudflare())->is_remote_active()) {
            $warnings[] = 'API token lacks Zone > Cache Rules > Edit. The site runs in page-rule mode. Re-issue the token with that permission, then re-save the Cloudflare settings to upgrade to cache rules.';
            return [
                'enabled' => true,
                'rule_mode' => Cloudflare::RULE_MODE_PAGE_RULE,
                'page_rule_state' => 'kept_adopted',
                'status_detail' => 'caching via existing Cloudflare Page Rule; purge sync active',
            ];
        }

        $edge->save_provider(Edge::CLOUDFLARE, $provider_settings, false);
        $warnings[] = 'Enable failed; reverted to inactive — the page rule was not confirmed.';
        return [
            'enabled' => false,
            'rule_mode' => null,
            'page_rule_state' => 'unknown',
            'status_detail' => 'enable failed; reverted. page rule not confirmed',
        ];
    }

    /**
     * Store Cloudflare credentials without enabling. Used by every early
     * exit: verify failed, zone pending, zone ID missing.
     *
     * @param string $provider Provider key.
     * @param array  $provider_settings Provider settings.
     * @return bool
     */
    private function save_cloudflare_inactive(string $provider, array $provider_settings): bool
    {
        return (new Edge())->save_provider($provider, $provider_settings, false);
    }

    /**
     * Classify the old credential by shape.
     * Up to 37 lowercase hex chars is a Global API Key; anything else is a token.
     *
     * @param string $raw Raw credential.
     * @return string 'key' | 'token'
     */
    private function classify_cf_auth_type(string $raw): string
    {
        $token = trim($raw);

        if ('' !== $token && strlen($token) <= 37 && preg_match('/^[0-9a-f]+$/', $token)) {
            return 'key';
        }

        return 'token';
    }

    /**
     * Restore purger settings from backup
     *
     * @param array $backup
     * @return array|null
     */
    private function restore_purger_settings(array $backup): ?array
    {
        $purger = new Purger();
        $purger_defaults = $purger->get_default_settings();

        $purger_settings = [];
        foreach ($purger_defaults as $key => $default_value) {
            if (isset($backup[$key])) {
                $backup_value = $backup[$key];

                $purger_settings[$key] = $backup_value;
            }
        }

        if (empty($purger_settings)) {
            return null;
        }

        $result = $purger->save_settings($purger_settings);

        return [
            'success' => $result,
            'settings' => $purger_settings
        ];
    }

    /**
     * Restore preloader settings from backup
     *
     * @param array $backup
     * @return array|null
     */
    private function restore_preloader_settings(array $backup): ?array
    {
        $preloader = new Preloader();
        $preloader_defaults = $preloader->get_default_settings();

        $preloader_settings = [];
        foreach ($preloader_defaults as $key => $default_value) {
            if (isset($backup[$key])) {
                $backup_value = $backup[$key];

                if ($key === 'preload_path_mch' && is_array($backup_value)) {
                    // preload_path_mch is stored as array, not string
                    $preloader_settings[$key] = $backup_value;
                } else {
                    $preloader_settings[$key] = $backup_value;
                }
            }
        }

        if (empty($preloader_settings)) {
            return null;
        }

        $result = $preloader->save_settings($preloader_settings);

        return [
            'success' => $result,
            'settings' => $preloader_settings
        ];
    }

    /**
     * Restore rules settings from backup
     *
     * @param array $backup
     * @return array|null
     */
    private function restore_rules_settings(array $backup): ?array
    {
        $rules = new Rules();
        $rules_defaults = $rules->get_settings();

        $rules_settings = [];
        foreach ($rules_defaults as $key => $null_value) {
            if (isset($backup[$key])) {
                $backup_value = $backup[$key];

                if (in_array($key, [
                    'exclude_url_mch',
                    'exclude_cookie_mch',
                    'exclude_browser_mch',
                    'exclude_visitorip_mch',
                    'ignore_query_mch',
                    'allow_query_mch',
                    'exclude_query_mch',
                ]) && is_array($backup_value)) {
                    // All _mch settings are stored as arrays, not strings
                    $rules_settings[$key] = $backup_value;
                } elseif ($key === 'cache_key_extra_var' && is_array($backup_value)) {
                    $rules_settings[$key] = implode("\n", $backup_value);
                } else {
                    $rules_settings[$key] = $backup_value;
                }
            }
        }

        if (empty($rules_settings)) {
            return null;
        }

        $result = $rules->save_settings($rules_settings);

        return [
            'success' => $result,
            'settings' => $rules_settings
        ];
    }

    /**
     * Enable the object cache when RunCloud Hub had it enabled.
     *
     * Only the enable flag migrates. Connection settings come from the
     * environment (wp-config constants), never from the backup. A flag
     * of 0 changes nothing, so a working cache is never disabled here.
     *
     * @param array $backup RunCloud Hub backup payload.
     * @return array{success: bool, settings: array}|null Null when the flag is absent.
     */
    private function restore_object_cache_settings(array $backup): ?array
    {
        if (!array_key_exists('redis_cache_onn', $backup)) {
            return null;
        }

        if (!is_scalar($backup['redis_cache_onn']) || 1 !== (int) $backup['redis_cache_onn']) {
            return ['success' => false, 'settings' => []];
        }

        $redis = new RedisObjectCache();
        $connection = $redis->get_settings();

        if (empty($connection)) {
            $this->logger->warning('Object cache not enabled: no Redis connection settings found', ['component' => 'Runcloud']);
            return ['success' => false, 'settings' => []];
        }

        if (!$redis->test_connection($connection)) {
            $this->logger->warning('Object cache not enabled: Redis connection test failed', ['component' => 'Runcloud']);
            return ['success' => false, 'settings' => []];
        }

        $success = (new ObjectCache($this->logger))->enable();

        if (!$success) {
            $this->logger->error('Object cache enable failed during RunCloud Hub migration', ['component' => 'Runcloud']);
        }

        return [
            'success' => $success,
            'settings' => $success ? ['enable' => true] : [],
        ];
    }
}
