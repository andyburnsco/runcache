<?php

namespace RunCache\Integrations;

use Exception;
use RunCache\Core\Rules;
use RunCache\Core\Setting;
use RunCache\Support\Logger;
use RunCache\Core\Edge;
use RunCache\Support\Server;
use RunCache\Support\Validator;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cloudflare cache integration. This class is the Cache Rules provider and the
 * base class for other Cloudflare-backed providers such as Cloudflare APO.
 */
class Cloudflare
{
    public const API_BASE_URL = 'https://api.cloudflare.com/client/v4';
    public const ENDPOINT_ZONES = '/zones';
    public const ENDPOINT_PURGE_CACHE = 'purge_cache';
    // No leading slash: build_url() urlencodes each path segment.
    public const ENDPOINT_PAGERULES = 'pagerules';

    /** How the zone cache configuration is managed: 'cache_rules' or 'page_rule'. */
    public const RULE_MODE_CACHE_RULES = 'cache_rules';
    public const RULE_MODE_PAGE_RULE = 'page_rule';

    protected Logger $logger;
    protected Setting $setting;

    protected array $default_settings = [
        'api_token' => '',
        'zone_id' => '',
        'auth_type' => 'token',
        'account_email' => '',
        'rule_mode' => self::RULE_MODE_CACHE_RULES,
    ];

    /** @var string Cloudflare API token credential. */
    protected string $api_token = '';

    /** @var string Cloudflare zone identifier. */
    protected string $zone_id = '';

    /** @var string Authentication mode: token or key. */
    protected string $auth_type = 'token';

    /** @var string Cloudflare account email for key authentication. */
    protected string $account_email = '';

    /** @var string Cache enforcement mode: cache_rules or page_rule. */
    protected string $rule_mode = self::RULE_MODE_CACHE_RULES;

    /**
     * Initialize the Cloudflare provider.
     *
     * @param Logger|null  $logger Optional logger override.
     * @param Setting|null $setting Optional settings store override.
     */
    public function __construct(
        ?Logger $logger = null,
        ?Setting $setting = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->setting = $setting ?? new Setting();

        $this->load_settings();
    }

    /**
     * Provider identity config. Subclasses override this array instead of
     * duplicating individual accessor methods.
     *
     * @var array<string, string>
     */
    protected array $provider_config = [
        'key' => 'cloudflare',
        'label' => 'Cloudflare',
        'cache_type_constant' => RUNCACHE_CACHE_TYPE_CLOUDFLARE,
        'settings_option_key' => 'edge',
    ];

    /**
     * Cache provider key used in the provider registry.
     *
     * @return string
     */
    protected function get_provider_key(): string
    {
        return $this->provider_config['key'];
    }

    /**
     * Human-readable provider label used in log and error messages.
     *
     * @return string
     */
    protected function get_provider_label(): string
    {
        return $this->provider_config['label'];
    }

    /**
     * Cache type constant emitted in the X-RunCache-Type header.
     *
     * @return string
     */
    protected function get_cache_type_constant(): string
    {
        return $this->provider_config['cache_type_constant'];
    }

    /**
     * WordPress option key used to persist settings.
     *
     * @return string
     */
    protected function get_settings_option_key(): string
    {
        return $this->provider_config['settings_option_key'];
    }

    /**
     * Filter the selective-purge chunk size for Cloudflare.
     *
     * @param int $chunk_size Maximum number of files per request.
     * @return int
     */
    protected function get_purge_chunk_size(int $chunk_size = 30): int
    {
        return (int) apply_filters('runcache_cloudflare_purge_chunk_size', $chunk_size);
    }

    /**
     * Initialize WordPress hooks
     *
     * @return void
     */
    public function init(): void
    {
        add_action('wp_ajax_runcache_save_cloudflare_settings', [$this, 'ajax_save_settings']);
        $edge = new \RunCache\Core\Edge(null, null, $this->setting);
        if ($edge->is_active($this->get_provider_key())) {
            add_action('send_headers', [$this, 'do_cache'], 99999);
            add_action('runcache_after_purge_all', [$this, 'purge_all_cache']);
            add_action('runcache_after_purge_post', [$this, 'purge_post_cache'], 10, 2);
            add_action('runcache_after_purge_url', [$this, 'purge_url_cache']);

            // Re-push cache rule on rules change to keep cache_key in sync.
            add_action('runcache_rules_updated', [$this, 'refresh_cache_rules']);
        }
    }

    /**
     * Re-install the Cloudflare cache rule. Triggered by runcache_rules_updated.
     * No-op in page_rule mode: a ruleset write would only log a 403.
     *
     * @return void
     */
    public function refresh_cache_rules(): void
    {
        if (self::RULE_MODE_PAGE_RULE === $this->rule_mode) {
            $this->logger->debug('Skipping cache-rule refresh in page_rule mode', ['provider' => $this->get_provider_label()]);
            return;
        }

        if (!$this->has_valid_credentials()) {
            return;
        }

        $this->logger->info('Refreshing cache rule after rules update', ['provider' => $this->get_provider_label()]);
        $this->install_rules();
    }

    /**
     * Send cache headers for current page.
     *
     * @return void
     */
    public function do_cache(): void
    {
        if (!function_exists('runcache_should_cache')) {
            require_once RUNCACHE_PLUGIN_DIR . 'includes/runcache-filter.php';
        }

        $should = runcache_should_cache();

        foreach ($this->get_cache_headers($should) as $header) {
            header($header);
        }

        if ($should) {
            // Emit Vary header for cache_key_extra_var headers.
            $vary_headers = self::get_vary_header_names();
            if (!empty($vary_headers)) {
                header('Vary: ' . implode(', ', $vary_headers));
            }
        }
    }

    /**
     * Build the cache-control headers for the current request. Cache-Control targets
     * the browser, the CDN headers the edge, X-Accel-Expires the origin nginx cache.
     *
     * @param bool $should_cache
     * @return array<int, string>
     */
    protected function get_cache_headers(bool $should_cache): array
    {
        if (!$should_cache) {
            return [
                'X-CF-Status: BYPASS',
                'CDN-Cache-Control: private, no-cache, no-store',
                'Cache-Control: private, no-store, no-cache, must-revalidate',
            ];
        }

        $headers = [
            'X-CF-Status: HIT',
            'Cache-Control: private, no-store, no-cache, must-revalidate',
            'Cloudflare-CDN-Cache-Control: max-age=3600',
            'CDN-Cache-Control: public, max-age=3600',
        ];

        // Origin cache TTL; nginx gives this header priority over no-store.
        $accel_expires = $this->get_x_accel_expires();
        if ($accel_expires > 0) {
            $headers[] = 'X-Accel-Expires: ' . $accel_expires;
        }

        return $headers;
    }

    /**
     * TTL in seconds for the origin nginx FastCGI cache (X-Accel-Expires).
     * Mirrors the plugin's 8-hour origin TTL. Return 0 to disable.
     *
     * @return int
     */
    protected function get_x_accel_expires(): int
    {
        return (int) apply_filters('runcache_cloudflare_x_accel_expires', 28800);
    }

    /**
     * Resolve cache_key_extra_var to a list of HTTP header names.
     *
     * @return array<int, string>
     */
    protected static function get_vary_header_names(): array
    {
        $rules = (new Rules())->get_settings();
        if (empty($rules['cache_key_extra_onn']) || empty($rules['cache_key_extra_var'])) {
            return [];
        }

        $headers = [];
        foreach (explode(',', $rules['cache_key_extra_var']) as $var) {
            $var = trim($var);
            if ($var === '' || !str_starts_with($var, 'HTTP_')) {
                continue;
            }
            $headers[] = Server::server_var_to_header_name($var);
        }

        return array_values(array_unique($headers));
    }

    /**
     * Enable Cloudflare cache
     * cache_rules mode installs the Cache Rule; page_rule mode ensures the page rule exists.
     *
     * @return bool
     */
    public function enable(): bool
    {
        if (self::RULE_MODE_PAGE_RULE === $this->rule_mode) {
            return $this->ensure_page_rule();
        }

        return $this->install_rules();
    }

    /**
     * Disable Cloudflare cache
     * cache_rules mode removes the Cache Rule; page_rule mode deletes the managed page rules.
     *
     * @return bool
     */
    public function disable(): bool
    {
        if (self::RULE_MODE_PAGE_RULE === $this->rule_mode) {
            return $this->delete_managed_page_rules();
        }

        return $this->remove_rules();
    }

    /**
     * Ensure the legacy-shape page rule exists; create it only when missing.
     *
     * @return bool
     */
    public function ensure_page_rule(): bool
    {
        if ($this->managed_page_rule_exists()) {
            return true;
        }

        return $this->install_page_rule();
    }

    /**
     * True when the RunCache cache rule for this domain exists on the zone.
     * Two GET requests; read-only.
     *
     * @return bool
     */
    public function is_rule_installed(): bool
    {
        try {
            if (!$this->has_valid_credentials()) {
                return false;
            }

            $ruleset_id = $this->get_cache_ruleset_id();
            if (is_wp_error($ruleset_id)) {
                return false;
            }

            $rules = $this->get_ruleset_rules($ruleset_id);
            if (is_wp_error($rules)) {
                return false;
            }

            $needle = '[RunCache - do not delete] ' . $this->get_site_domain();
            foreach ($rules as $rule) {
                if (($rule['description'] ?? '') === $needle) {
                    return true;
                }
            }

            return false;
        } catch (Exception $exception) {
            return false;
        }
    }

    /**
     * True when the zone confirms the managed cache rule or the legacy page rule.
     * False on any error, so callers revert.
     *
     * @return bool
     */
    public function is_remote_active(): bool
    {
        if (self::RULE_MODE_PAGE_RULE === $this->rule_mode) {
            return $this->managed_page_rule_exists();
        }

        return $this->is_rule_installed();
    }

    /**
     * Install Cloudflare cache rule for this domain
     *
     * @return bool
     */
    private function install_rules(): bool
    {
        try {
            if ($this->api_token === '' || $this->zone_id === '') {
                $this->logger->error('Cannot install rules: API token or Zone ID missing');
                return false;
            }

            $domain = $this->get_site_domain();
            if (empty($domain)) {
                $this->logger->error('Cannot install rules: Failed to determine site domain');
                return false;
            }

            $ruleset_id = $this->get_or_create_cache_ruleset_id();
            if (is_wp_error($ruleset_id)) {
                $this->logger->error('Failed to fetch or create ruleset', [
                    'provider' => $this->get_provider_label(),
                    'error' => $ruleset_id->get_error_message(),
                ]);
                return false;
            }

            $existing_rules = $this->get_ruleset_rules($ruleset_id);
            if (is_wp_error($existing_rules)) {
                $this->logger->error('Failed to fetch ruleset details', [
                    'error' => $existing_rules->get_error_message(),
                ]);
                return false;
            }

            $existing_rules = $this->remove_managed_rule($existing_rules, $domain);

            $action_parameters = [
                'cache' => true,
                'edge_ttl' => ['mode' => 'respect_origin'],
                'browser_ttl' => ['mode' => 'respect_origin'],
            ];

            // Add cache_key_extra_var headers to the Cloudflare cache key.
            $cache_key_extra_headers = $this->get_cache_key_extra_headers();
            if (!empty($cache_key_extra_headers)) {
                $action_parameters['cache_key'] = [
                    'custom_key' => [
                        'header' => [
                            'include' => $cache_key_extra_headers,
                            'check_presence' => $cache_key_extra_headers,
                        ],
                    ],
                ];
            }

            $rule_description = '[RunCache - do not delete] ' . $domain;
            $cache_rule = [
                'action' => 'set_cache_settings',
                'action_parameters' => $action_parameters,
                'description' => $rule_description,
                'enabled' => true,
                'expression' => $this->build_cache_rule_expression($domain),
            ];

            $proposed_rules = array_merge([$cache_rule], $existing_rules);
            $payload = ['rules' => $proposed_rules];

            $url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, 'rulesets', $ruleset_id]);
            $response = $this->make_request('PUT', $url, $payload);

            // Custom cache keys need an Enterprise plan. On a plan error, retry
            // without the cache-key section so the rule still installs.
            if (is_wp_error($response) && !empty($cache_key_extra_headers) && $this->is_custom_cache_key_error($response->get_error_message())) {
                $this->logger->warning('Custom cache-key headers are not supported by this zone plan; retrying without them', ['provider' => $this->get_provider_label()]);

                $fallback_rule = $cache_rule;
                unset($fallback_rule['action_parameters']['cache_key']);
                $payload = ['rules' => array_merge([$fallback_rule], $existing_rules)];
                $response = $this->make_request('PUT', $url, $payload);
            }

            if (is_wp_error($response)) {
                $this->logger->error('Failed to install cache rules', [
                    'provider' => $this->get_provider_label(),
                    'error' => $response->get_error_message(),
                ]);
                return false;
            }

            $this->logger->info('Cache rule installed', ['provider' => $this->get_provider_label()]);
            return true;
        } catch (Exception $exception) {
            $this->logger->error('Failed to install cache rules', [
                'provider' => $this->get_provider_label(),
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove Cloudflare cache rule for this domain
     *
     * @return bool
     */
    private function remove_rules(): bool
    {
        try {
            if ($this->api_token === '' || $this->zone_id === '') {
                return true;
            }

            $domain = $this->get_site_domain();
            if (empty($domain)) {
                return true;
            }

            $ruleset_id = $this->get_cache_ruleset_id();
            if (is_wp_error($ruleset_id)) {
                return true;
            }

            $existing_rules = $this->get_ruleset_rules($ruleset_id);
            if (is_wp_error($existing_rules)) {
                return true;
            }

            $filtered_rules = $this->remove_managed_rule($existing_rules, $domain);

            $url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, 'rulesets', $ruleset_id]);
            $response = $this->make_request('PUT', $url, ['rules' => $filtered_rules]);

            if (is_wp_error($response)) {
                $this->logger->error('Failed to remove cache rules', [
                    'provider' => $this->get_provider_label(),
                    'error' => $response->get_error_message(),
                ]);
                return false;
            }

            $this->logger->info('Cache rule removed', ['provider' => $this->get_provider_label()]);
            return true;
        } catch (Exception $exception) {
            $this->logger->error('Failed to remove cache rules', [
                'provider' => $this->get_provider_label(),
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get rulesets for the zone
     *
     * @return mixed
     */
    private function get_rulesets()
    {
        if ($this->zone_id === '') {
            return new WP_Error('cloudflare_zone_missing', 'Zone ID is not configured');
        }

        $url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, 'rulesets']);
        return $this->make_request('GET', $url);
    }

    /**
     * Explain a ruleset
     *
     * @param string $ruleset_id
     * @return mixed
     */
    private function explain_ruleset(string $ruleset_id)
    {
        if ($this->zone_id === '') {
            return new WP_Error('cloudflare_zone_missing', 'Zone ID is not configured');
        }

        $url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, 'rulesets', $ruleset_id]);
        return $this->make_request('GET', $url);
    }

    /**
     * Find the http_request_cache_settings ruleset ID for the current zone.
     *
     * @return string|WP_Error
     */
    private function get_cache_ruleset_id()
    {
        if ($this->zone_id === '') {
            return new WP_Error('cloudflare_zone_missing', 'Zone ID is not configured');
        }

        $response = $this->get_rulesets();
        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        foreach ($body['result'] ?? [] as $ruleset) {
            if (($ruleset['phase'] ?? '') === 'http_request_cache_settings') {
                return $ruleset['id'];
            }
        }

        return new WP_Error('cache_settings_ruleset_not_found', 'Cache settings ruleset not found');
    }

    /**
     * Return the http_request_cache_settings ruleset ID. Create the ruleset
     * when the zone has no ruleset for this phase yet.
     *
     * @return string|WP_Error
     */
    private function get_or_create_cache_ruleset_id()
    {
        $ruleset_id = $this->get_cache_ruleset_id();

        if (!is_wp_error($ruleset_id)) {
            return $ruleset_id;
        }

        // Create only when the phase is absent. Do not mask API errors.
        if ($ruleset_id->get_error_code() !== 'cache_settings_ruleset_not_found') {
            return $ruleset_id;
        }

        return $this->create_cache_ruleset();
    }

    /**
     * Create the http_request_cache_settings ruleset for the zone.
     *
     * @return string|WP_Error
     */
    private function create_cache_ruleset()
    {
        $url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, 'rulesets']);
        $response = $this->make_request('POST', $url, [
            'name' => 'RunCache',
            'kind' => 'zone',
            'phase' => 'http_request_cache_settings',
            'rules' => [],
        ]);

        if (is_wp_error($response)) {
            // A concurrent request may have created it. Re-read before failing.
            $retry = $this->get_cache_ruleset_id();
            return is_wp_error($retry) ? $response : $retry;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $ruleset_id = is_array($body) ? ($body['result']['id'] ?? '') : '';

        if (!is_string($ruleset_id) || $ruleset_id === '') {
            return $this->get_cache_ruleset_id();
        }

        $this->logger->info('Cache settings ruleset created', [
            'provider' => $this->get_provider_label(),
        ]);

        return $ruleset_id;
    }

    /**
     * Get the rules for a given ruleset ID.
     *
     * @param string $ruleset_id
     * @return array|WP_Error
     */
    private function get_ruleset_rules(string $ruleset_id)
    {
        $response = $this->explain_ruleset($ruleset_id);
        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['result']['rules'] ?? [];
    }

    /**
     * Remove the RunCache-managed rule for a domain from a list of rules.
     *
     * @param array  $rules Rule list from the Cloudflare API.
     * @param string $domain Site domain to match.
     * @return array
     */
    private function remove_managed_rule(array $rules, string $domain): array
    {
        $rule_description = '[RunCache - do not delete] ' . $domain;

        return array_values(array_filter($rules, function ($rule) use ($rule_description) {
            $description = $rule['description'] ?? '';
            // Remove only the RunCache rule for this specific domain
            return $description !== $rule_description;
        }));
    }

    /**
     * Build the Cloudflare cache rule expression for a domain.
     *
     * @param string $domain
     * @return string
     */
    private function build_cache_rule_expression(string $domain): string
    {
        // Only GET/HEAD are cache-safe. The cookie exclusions keep logged-in
        // visitors and WooCommerce sessions out of the edge cache.
        return '(http.host eq "' . $domain . '" and http.request.method in {"GET" "HEAD"} and not http.cookie contains "wordpress_logged_in" and not http.cookie contains "woocommerce")';
    }

    /**
     * List active page rules for the zone.
     *
     * @return array|WP_Error The raw rules list, or the API error.
     */
    public function get_page_rules(): array|WP_Error
    {
        if (!$this->has_valid_credentials()) {
            return new WP_Error('cloudflare_credentials_missing', 'API token or Zone ID missing');
        }

        $url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, self::ENDPOINT_PAGERULES]);
        $url .= '?status=active';

        $response = $this->make_request('GET', $url);
        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $rules = $body['result'] ?? [];

        if (!is_array($rules)) {
            return new WP_Error('cloudflare_pagerules_invalid_response', 'Unexpected page rules response format');
        }

        return array_values(array_filter($rules, 'is_array'));
    }

    /**
     * True when an active rule matches a legacy shape: target '<hostname>/*'
     * (with optional scheme) and the single action cache_level = cache_everything.
     *
     * @return bool
     */
    public function managed_page_rule_exists(): bool
    {
        $rules = $this->get_page_rules();
        if (is_wp_error($rules)) {
            return false;
        }

        foreach ($rules as $rule) {
            if ($this->is_managed_page_rule($rule)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create the legacy Cache Everything page rule for this site. Used only in
     * page_rule mode when the rule is missing.
     *
     * @return bool
     */
    public function install_page_rule(): bool
    {
        try {
            if (!$this->has_valid_credentials()) {
                $this->logger->error('Cannot install page rule: API token or Zone ID missing', ['provider' => $this->get_provider_label()]);
                return false;
            }

            $domain = $this->get_site_domain();
            if ('' === $domain) {
                $this->logger->error('Cannot install page rule: Failed to determine site domain', ['provider' => $this->get_provider_label()]);
                return false;
            }

            $url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, self::ENDPOINT_PAGERULES]);
            $response = $this->make_request('POST', $url, [
                'targets' => [
                    [
                        'target' => 'url',
                        'constraint' => [
                            'operator' => 'matches',
                            'value' => $domain . '/*',
                        ],
                    ],
                ],
                'actions' => [
                    [
                        'id' => 'cache_level',
                        'value' => 'cache_everything',
                    ],
                ],
                'status' => 'active',
                'priority' => 1,
            ]);

            if (is_wp_error($response)) {
                $this->logger->error('Failed to install page rule', [
                    'provider' => $this->get_provider_label(),
                    'error' => $response->get_error_message(),
                ]);
                return false;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($body) || empty($body['success'])) {
                $error = new WP_Error('cloudflare_pagerule_install_failed', $body['errors'][0]['message'] ?? 'Unknown error occurred');
                $this->logger->error('Failed to install page rule', [
                    'provider' => $this->get_provider_label(),
                    'error' => $error->get_error_message(),
                ]);
                return false;
            }

            $this->logger->info('Page rule installed', ['provider' => $this->get_provider_label()]);
            return true;
        } catch (Exception $exception) {
            $this->logger->error('Failed to install page rule', [
                'provider' => $this->get_provider_label(),
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Delete every active page rule matching the three legacy shapes. Used by
     * the confirmation-gated cleanup and by page_rule-mode disable().
     *
     * @return bool True when nothing failed (including when no rule matched).
     */
    public function delete_managed_page_rules(): bool
    {
        try {
            if (!$this->has_valid_credentials()) {
                $this->logger->error('Cannot delete page rules: API token or Zone ID missing', ['provider' => $this->get_provider_label()]);
                return false;
            }

            $rules = $this->get_page_rules();
            if (is_wp_error($rules)) {
                $this->logger->warning('Failed to list Cloudflare page rules', [
                    'provider' => $this->get_provider_label(),
                    'error' => $rules->get_error_message(),
                ]);
                return false;
            }

            $failed = 0;
            foreach ($rules as $rule) {
                if (!$this->is_managed_page_rule($rule)) {
                    continue;
                }

                $rule_id = (string) ($rule['id'] ?? '');
                if ('' === $rule_id) {
                    continue;
                }

                $url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, self::ENDPOINT_PAGERULES, $rule_id]);
                $response = $this->make_request('DELETE', $url);

                if (is_wp_error($response)) {
                    ++$failed;
                    $this->logger->warning('Failed to delete legacy page rule', [
                        'provider' => $this->get_provider_label(),
                        'rule_id' => $rule_id,
                        'error' => $response->get_error_message(),
                    ]);
                    continue;
                }

                $this->logger->info('Deleted legacy page rule', [
                    'provider' => $this->get_provider_label(),
                    'rule_id' => $rule_id,
                ]);
            }

            return 0 === $failed;
        } catch (Exception $exception) {
            $this->logger->error('Failed to delete legacy page rules', [
                'provider' => $this->get_provider_label(),
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * The three page-rule target shapes the old plugin wrote.
     *
     * @return array<int, string>
     */
    private function get_managed_page_rule_targets(): array
    {
        $domain = $this->get_site_domain();
        if ('' === $domain) {
            return [];
        }

        return [
            $domain . '/*',
            'http://' . $domain . '/*',
            'https://' . $domain . '/*',
        ];
    }

    /**
     * True when a rule matches one of the three legacy shapes: an active rule
     * targeting '<hostname>/*' with the single action cache_level = cache_everything.
     *
     * @param mixed $rule Rule payload from the Cloudflare API.
     * @return bool
     */
    private function is_managed_page_rule(mixed $rule): bool
    {
        if (!is_array($rule) || ($rule['status'] ?? '') !== 'active') {
            return false;
        }

        $targets = $rule['targets'] ?? [];
        if (!is_array($targets) || count($targets) !== 1) {
            return false;
        }

        $constraint = $targets[0]['constraint'] ?? [];
        $target_value = is_array($constraint) ? (string) ($constraint['value'] ?? '') : '';

        if (!in_array($target_value, $this->get_managed_page_rule_targets(), true)) {
            return false;
        }

        $actions = $rule['actions'] ?? [];
        if (!is_array($actions) || count($actions) !== 1) {
            return false;
        }

        $action = $actions[0];
        if (!is_array($action)) {
            return false;
        }

        return ($action['id'] ?? '') === 'cache_level'
            && (string) ($action['value'] ?? '') === 'cache_everything';
    }

    /**
     * Get site domain
     *
     * @return string
     */
    protected function get_site_domain(): string
    {
        $domain = wp_parse_url(home_url('/'), PHP_URL_HOST);
        return is_string($domain) ? $domain : '';
    }

    /**
     * Check whether a Cloudflare zone name matches the site domain.
     *
     * Allows exact match, www/apex pairing, or subdomain-of-zone match.
     *
     * @param string $zone_name
     * @param string $site_domain
     * @return bool
     */
    protected function zone_matches_domain(string $zone_name, string $site_domain): bool
    {
        if ($zone_name === '' || $site_domain === '') {
            return true; // cannot determine, defer to API success
        }

        return $this->get_domain_match_score($zone_name, $site_domain) > 0;
    }

    /**
     * Score how well a Cloudflare zone name matches the site domain. Higher
     * scores are better: 3 = exact, 2 = subdomain, 1 = www/apex pair, 0 = none.
     *
     * @param string $zone_name
     * @param string $site_domain
     * @return int
     */
    protected function get_domain_match_score(string $zone_name, string $site_domain): int
    {
        if ($zone_name === '' || $site_domain === '') {
            return 0;
        }

        $zone = strtolower(rtrim($zone_name, '.'));
        $site = strtolower(rtrim($site_domain, '.'));

        if ($zone === $site) {
            return 3;
        }

        // Site is a subdomain of the zone. Example: sub.c.com under the c.com zone.
        if (str_ends_with($site, '.' . $zone)) {
            return 2;
        }

        // Match a www.example.com zone with an example.com site, and the reverse.
        if ($zone === 'www.' . $site || 'www.' . $zone === $site) {
            return 1;
        }

        return 0;
    }

    /**
     * Find the best matching Cloudflare zone for the current site. Preference
     * order: exact match, parent zone, www/apex pair. Ties go to the longest name.
     *
     * @param array<int, array> $zones
     * @return array|null
     */
    protected function find_best_matching_zone(array $zones): ?array
    {
        $site_domain = $this->get_site_domain();
        if ($site_domain === '' || $zones === []) {
            return null;
        }

        $scored = [];
        foreach ($zones as $zone) {
            if (!is_array($zone)) {
                continue;
            }

            $zone_name = $zone['name'] ?? '';
            $score = $this->get_domain_match_score($zone_name, $site_domain);
            if ($score <= 0) {
                continue;
            }

            $scored[] = [
                'zone' => $zone,
                'score' => $score,
                'length' => strlen($zone_name),
            ];
        }

        if ($scored === []) {
            return null;
        }

        // Highest score first. Equal scores: prefer the longest zone name.
        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $b['length'] <=> $a['length'];
        });

        return $scored[0]['zone'];
    }

    /**
     * Fetch every zone the current credentials can access. Cloudflare returns
     * 20 zones per page by default, so scoring one page misses later zones.
     *
     * @return array<int, array>|WP_Error
     */
    private function get_all_zones()
    {
        $zones = [];
        $page = 1;
        $total_pages = 1;

        // Guard against a looping or malformed API response.
        $max_pages = 200;

        while ($page <= $total_pages && $page <= $max_pages) {
            $url = $this->build_url(self::ENDPOINT_ZONES, [], [
                'per_page' => 50,
                'page' => $page,
            ]);

            $response = $this->make_request('GET', $url);
            if (is_wp_error($response)) {
                return $response;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($body) || empty($body['success'])) {
                return new WP_Error('cloudflare_zones_failed', 'Unable to list Cloudflare zones');
            }

            $result = $body['result'] ?? [];
            if (!is_array($result)) {
                return new WP_Error('cloudflare_zones_failed', 'Unable to list Cloudflare zones');
            }

            $zones = array_merge($zones, $result);

            $info = $body['result_info'] ?? [];
            $reported = is_array($info) ? (int) ($info['total_pages'] ?? 1) : 1;
            $total_pages = $reported > 0 ? $reported : 1;
            $page++;
        }

        return $zones;
    }

    /**
     * Detect the best Cloudflare zone for this site using temporary credentials.
     *
     * @param string $api_token
     * @param string $auth_type
     * @param string $account_email
     * @return array
     * @throws Exception
     */
    protected function detect_best_zone(string $api_token, string $auth_type, string $account_email): array
    {
        $api_token = $this->normalize_api_token($api_token);

        if ($api_token === '') {
            throw new Exception(esc_html__('Cloudflare API token is required.', 'runcache'));
        }

        if ($auth_type === 'key' && $account_email === '') {
            throw new Exception('Cloudflare account email is required when using an API key');
        }

        $zones = $this->with_credentials([
            'api_token' => $api_token,
            'auth_type' => $auth_type,
            'account_email' => $account_email,
        ], function () {
            return $this->get_all_zones();
        });

        if (is_wp_error($zones)) {
            $error_message = $zones->get_error_message();
            $this->logger->error('Cloudflare zone detection failed', [
                'provider' => $this->get_provider_label(),
                'error' => $error_message,
            ]);

            if (stripos($error_message, 'invalid request headers') !== false) {
                throw new Exception(esc_html__('Cloudflare credentials are invalid. Please check your API token.', 'runcache'));
            }

            throw new Exception(esc_html__('Unable to detect the Cloudflare zone. Please check your credentials and try again.', 'runcache'));
        }

        $zone = $this->find_best_matching_zone($zones);
        if ($zone === null || !isset($zone['id'])) {
            throw new Exception(esc_html__('No matching Cloudflare zone was found. Make sure the domain is added to Cloudflare and the API token has access to it.', 'runcache'));
        }

        return $zone;
    }

    /**
     * Populate settings with the auto-detected zone_id.
     *
     * @param array $settings
     * @return array
     * @throws Exception
     */
    public function detect_zone_for_settings(array $settings): array
    {
        $api_token = $settings['api_token'] ?? '';
        $auth_type = strtolower(sanitize_text_field($settings['auth_type'] ?? 'token'));
        if (!in_array($auth_type, ['token', 'key'], true)) {
            $auth_type = 'token';
        }
        $account_email = sanitize_email($settings['account_email'] ?? '');

        $zone = $this->detect_best_zone($api_token, $auth_type, $account_email);
        $settings['zone_id'] = $zone['id'];

        return $settings;
    }

    /**
     * Verify Cloudflare setup
     *
     * @return array
     */
    public function verify(): array
    {
        try {
            if (!$this->has_valid_credentials()) {
                return [
                    'verified' => false,
                    'message' => $this->get_verify_missing_credentials_message(),
                ];
            }

            $response = $this->make_request('GET', $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id]));

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                $this->logger->error('Cloudflare verification request failed', [
                    'provider' => $this->get_provider_label(),
                    'error' => $error_message,
                ]);

                return [
                    'verified' => false,
                    'message' => sprintf(
                        /* translators: %s: provider label */
                        __('%s credentials are invalid. Please check your API token.', 'runcache'),
                        $this->get_provider_label()
                    ),
                ];
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $result = $body['result'] ?? [];

            $domain = $this->get_site_domain();
            $zone_name = $result['name'] ?? '';
            if ($zone_name !== '' && !$this->zone_matches_domain($zone_name, $domain)) {
                return [
                    'verified' => false,
                    'message' => $this->get_verify_zone_mismatch_message($domain, $zone_name),
                ];
            }

            return [
                'verified' => true,
                'message' => $this->get_verify_success_message(),
                'data' => $result,
            ];
        } catch (Exception $exception) {
            $this->logger->error('Failed to verify provider credentials', [
                'provider' => $this->get_provider_label(),
                'error' => $exception->getMessage(),
            ]);

            return [
                'verified' => false,
                'message' => sprintf(
                    /* translators: %s: provider label */
                    __('%s credentials are invalid. Please check your API token.', 'runcache'),
                    $this->get_provider_label()
                ),
            ];
        }
    }

    /**
     * User-facing message when credentials are missing during verify().
     *
     * @return string
     */
    protected function get_verify_missing_credentials_message(): string
    {
        return __('Cloudflare API token and Zone ID are required.', 'runcache');
    }

    /**
     * User-facing message when the configured zone does not match the site domain.
     *
     * @param string $site_domain
     * @param string $zone_name
     * @return string
     */
    protected function get_verify_zone_mismatch_message(string $site_domain, string $zone_name): string
    {
        return sprintf(
            /* translators: %1$s: site domain; %2$s: selected Cloudflare zone name */
            __('Cloudflare verification failed: the selected zone "%2$s" does not match the site domain "%1$s".', 'runcache'),
            $site_domain,
            $zone_name
        );
    }

    /**
     * User-facing success message for verify().
     *
     * @return string
     */
    protected function get_verify_success_message(): string
    {
        return __('Cloudflare connection successful.', 'runcache');
    }

    /**
     * Get settings
     *
     * @return array
     */
    public function get_settings(): array
    {
        $edge_settings = (new \RunCache\Core\Edge(null, null, $this->setting))->get_provider_settings($this->get_provider_key());
        return wp_parse_args($edge_settings, $this->default_settings);
    }

    /**
     * Save settings
     *
     * @param array $settings
     * @return bool
     */
    public function save_settings(array $settings): bool
    {
        try {
            $merged_settings = wp_parse_args($settings, $this->default_settings);

            $auth_type = strtolower(sanitize_text_field($merged_settings['auth_type'] ?? 'token'));
            if (!in_array($auth_type, ['token', 'key'], true)) {
                $auth_type = 'token';
            }

            $account_email = sanitize_email($merged_settings['account_email'] ?? '');

            $sanitized_settings = array_merge([
                'api_token' => $this->normalize_api_token($merged_settings['api_token']),
                'zone_id' => sanitize_text_field($merged_settings['zone_id']),
                'auth_type' => $auth_type,
                'account_email' => $account_email,
            ], $this->sanitize_extra_settings($merged_settings));

            if ($sanitized_settings['api_token'] === '') {
                throw new Exception(__('Cloudflare API token is required.', 'runcache'));
            }

            if ($sanitized_settings['zone_id'] === '') {
                throw new Exception(__('Cloudflare Zone ID is required.', 'runcache'));
            }

            if ($sanitized_settings['auth_type'] === 'key' && $sanitized_settings['account_email'] === '') {
                throw new Exception('Cloudflare account email is required when using an API key');
            }

            $result = (new \RunCache\Core\Edge(null, null, $this->setting))->save_provider(
                $this->get_provider_key(),
                $sanitized_settings,
                true
            );
            if (!$result) {
                throw new Exception(__('Failed to save Cloudflare settings.', 'runcache'));
            }

            $this->api_token = $sanitized_settings['api_token'];
            $this->zone_id = $sanitized_settings['zone_id'];
            $this->auth_type = $sanitized_settings['auth_type'];
            $this->account_email = $sanitized_settings['account_email'];
            $this->load_extra_settings($sanitized_settings);

            $this->logger->info('Settings saved successfully', ['provider' => $this->get_provider_label()]);
            return true;
        } catch (Exception $exception) {
            $this->logger->error('Failed to save settings', [
                'provider' => $this->get_provider_label(),
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Sanitize provider-specific extra settings before persisting. Subclasses
     * can override this to store fields beyond the common credential set.
     *
     * @param array $merged_settings Settings merged with defaults.
     * @return array Extra sanitized fields to merge into the saved option.
     */
    protected function sanitize_extra_settings(array $merged_settings): array
    {
        return [
            'rule_mode' => $this->sanitize_rule_mode($merged_settings['rule_mode'] ?? self::RULE_MODE_CACHE_RULES),
        ];
    }

    /**
     * AJAX handler for getting settings
     *
     * @return void
     */
    public function ajax_get_settings(): void
    {
        (new Validator())->validate_ajax_request();
        wp_send_json_success($this->get_settings());
    }

    /**
     * AJAX handler for saving settings
     *
     * @return void
     */
    public function ajax_save_settings(): void
    {
        try {
            (new Validator())->validate_ajax_request();

            $decoded_settings = (new Validator())->validate_ajax_settings();

            $decoded_settings = $this->detect_zone_for_settings($decoded_settings);

            $edge = new \RunCache\Core\Edge(null, null, $this->setting);
            $pre_save_snapshot = $edge->get_settings();
            $was_page_rule_mode = (($pre_save_snapshot['provider'][$this->get_provider_key()]['rule_mode'] ?? '')
                === self::RULE_MODE_PAGE_RULE);

            if (!$this->save_settings($decoded_settings)) {
                throw new Exception(__('Unable to save Cloudflare settings.', 'runcache'));
            }

            // Lying-state guard. save_provider() discards the enable result.
            // Confirm the rule on the zone before reporting success.
            if (!$this->is_remote_active()) {
                $detail = __('the cache rule could not be confirmed on the zone', 'runcache');

                $this->disable();
                $edge->save_settings($pre_save_snapshot);

                throw new Exception(
                    sprintf(
                        /* translators: %s: remote failure detail */
                        __('Cloudflare settings were reverted because enabling failed: %s', 'runcache'),
                        $detail
                    )
                );
            }

            // Upgrade path: after a confirmed cache-rule install, remove the
            // legacy page rules. Cleanup failure is a warning, never fatal.
            $message = $this->get_provider_label() . ' settings saved successfully';
            if ($was_page_rule_mode && !$this->delete_managed_page_rules()) {
                $message .= ' ' . __('Warning: the legacy page rules could not be deleted; remove them from the Cloudflare dashboard.', 'runcache');
            }

            wp_send_json_success([
                'message' => $message,
                'data' => $this->get_settings(),
            ]);
        } catch (Exception $exception) {
            $this->logger->error('Failed to save ' . $this->get_provider_label() . ' settings', [
                'error' => $exception->getMessage(),
            ]);
            wp_send_json_error([
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Purge all cache
     *
     * @return void
     */
    public function purge_all_cache(): void
    {
        try {
            if (!$this->has_valid_credentials()) {
                throw new Exception('API token or Zone ID is not configured');
            }

            $url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, self::ENDPOINT_PURGE_CACHE]);
            $response = $this->make_request('POST', $url, [
                'purge_everything' => true,
            ]);

            if (is_wp_error($response)) {
                throw new Exception(
                    __('Unable to purge Cloudflare cache. Please try again.', 'runcache')
                );
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (!is_array($body) || empty($body['success'])) {
                throw new Exception(
                    __('Unable to purge Cloudflare cache. Please try again.', 'runcache')
                );
            }

            $this->logger->info('Successfully purged all cache', [
                'provider' => $this->get_provider_label(),
                'success' => $body['success'] ?? null,
                'errors' => $body['errors'] ?? [],
            ]);
        } catch (Exception $exception) {
            $this->logger->error('Failed to purge all cache', [
                'provider' => $this->get_provider_label(),
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Purge post cache
     *
     * @param int   $post_id Post id to purge.
     * @param array $urls URLs to purge.
     * @return void
     */
    public function purge_post_cache(int $post_id, array $urls): void
    {
        try {
            if (!$this->has_valid_credentials()) {
                throw new Exception('API token or Zone ID is not configured');
            }

            $urls = array_values(array_filter($urls, 'is_string'));

            if (empty($urls)) {
                $this->logger->info('No URLs to purge for post', [
                    'provider' => $this->get_provider_label(),
                    'post_id' => $post_id,
                ]);
                return;
            }

            $result = $this->purge_files($urls);

            if (!empty($result['errors'])) {
                $this->logger->error('Failed to purge cache for post', [
                    'provider' => $this->get_provider_label(),
                    'post_id' => $post_id,
                    'errors' => $result['errors'],
                ]);
            } else {
                $this->logger->info('Successfully purged cache for post', [
                    'provider' => $this->get_provider_label(),
                    'post_id' => $post_id,
                    'url_count' => $result['success'],
                ]);
            }
        } catch (Exception $exception) {
            $this->logger->error('Failed to purge cache for post', [
                'provider' => $this->get_provider_label(),
                'post_id' => $post_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Purge URL cache
     *
     * @param string $url
     * @return void
     */
    public function purge_url_cache(string $url): void
    {
        try {
            if (!$this->has_valid_credentials()) {
                throw new Exception('API token or Zone ID is not configured');
            }

            if ($url === '') {
                $this->logger->info('No URL to purge', ['provider' => $this->get_provider_label()]);
                return;
            }

            $result = $this->purge_files([$url]);

            if (!empty($result['errors'])) {
                $this->logger->error('Failed to purge cache for URL', [
                    'provider' => $this->get_provider_label(),
                    'url' => $url,
                    'errors' => $result['errors'],
                ]);
            } else {
                $this->logger->info('Successfully purged cache for URL', [
                    'provider' => $this->get_provider_label(),
                    'url' => $url,
                ]);
            }
        } catch (Exception $exception) {
            $this->logger->error('Failed to purge cache for URL', [
                'provider' => $this->get_provider_label(),
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Send selective purge requests for a list of files, chunked to respect
     * Cloudflare's per-request URL limit.
     *
     * @param array<int, string> $files
     * @return array<string, mixed>
     */
    protected function purge_files(array $files): array
    {
        $chunk_size = $this->get_purge_chunk_size();
        $chunk_size = max(1, $chunk_size);

        $chunks = array_chunk($files, $chunk_size);
        $base_url = $this->build_url(self::ENDPOINT_ZONES, [$this->zone_id, self::ENDPOINT_PURGE_CACHE]);

        $success_count = 0;
        $errors = [];

        foreach ($chunks as $chunk) {
            $response = $this->make_request('POST', $base_url, [
                'files' => $chunk,
            ]);

            if (is_wp_error($response)) {
                $errors[] = $response->get_error_message();
                $this->logger->error('Failed to purge files chunk', [
                    'provider' => $this->get_provider_label(),
                    'error' => $response->get_error_message(),
                    'files' => $chunk,
                ]);
                continue;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($body) || empty($body['success'])) {
                $message = is_array($body) ? ($body['errors'][0]['message'] ?? 'Unknown error occurred') : 'Unknown error occurred';
                $errors[] = $message;
                $this->logger->error('Failed to purge files chunk', [
                    'provider' => $this->get_provider_label(),
                    'error' => $message,
                    'files' => $chunk,
                ]);
                continue;
            }

            $success_count += count($chunk);
            $this->logger->info('Purged files chunk', [
                'provider' => $this->get_provider_label(),
                'count' => count($chunk),
            ]);
        }

        return [
            'success' => $success_count,
            'errors' => $errors,
        ];
    }

    /**
     * Load settings from storage
     *
     * @return void
     */
    protected function load_settings(): void
    {
        $settings = $this->get_settings();
        $this->api_token = $this->normalize_api_token($settings['api_token'] ?? '');
        $this->zone_id = (string) ($settings['zone_id'] ?? '');
        $this->auth_type = (string) ($settings['auth_type'] ?? 'token');
        $this->account_email = (string) ($settings['account_email'] ?? '');
        $this->load_extra_settings($settings);
    }

    /**
     * Load provider-specific extra settings into instance properties.
     *
     * @param array $settings The full saved settings array.
     * @return void
     */
    protected function load_extra_settings(array $settings): void
    {
        $this->rule_mode = $this->sanitize_rule_mode($settings['rule_mode'] ?? self::RULE_MODE_CACHE_RULES);
    }

    /**
     * Keep only the two known rule modes.
     *
     * @param mixed $rule_mode Raw rule mode value.
     * @return string
     */
    protected function sanitize_rule_mode(mixed $rule_mode): string
    {
        return self::RULE_MODE_PAGE_RULE === $rule_mode
            ? self::RULE_MODE_PAGE_RULE
            : self::RULE_MODE_CACHE_RULES;
    }

    /**
     * Check if credentials are valid
     *
     * @return bool
     */
    protected function has_valid_credentials(): bool
    {
        return $this->api_token !== '' && $this->zone_id !== '';
    }

    /**
     * Run an operation with temporary credentials.
     * The method always restores the previous credentials, even on a throw.
     *
     * @param array    $credentials api_token, zone_id, auth_type, account_email.
     * @param callable $operation Callback returning the operation result.
     * @return mixed The operation result.
     */
    public function with_credentials(array $credentials, callable $operation): mixed
    {
        $original_token = $this->api_token;
        $original_zone_id = $this->zone_id;
        $original_auth_type = $this->auth_type;
        $original_email = $this->account_email;

        $this->api_token = $this->normalize_api_token( (string) ($credentials['api_token'] ?? ''));
        $this->zone_id = sanitize_text_field( (string) ($credentials['zone_id'] ?? ''));
        $this->auth_type = (($credentials['auth_type'] ?? 'token') === 'key') ? 'key' : 'token';
        $this->account_email = sanitize_email( (string) ($credentials['account_email'] ?? ''));

        try {
            return $operation();
        } finally {
            $this->api_token = $original_token;
            $this->zone_id = $original_zone_id;
            $this->auth_type = $original_auth_type;
            $this->account_email = $original_email;
        }
    }

    /**
     * Resolve cache_key_extra_var to header names; filters restricted headers.
     *
     * @return array<int, string>
     */
    private function get_cache_key_extra_headers(): array
    {
        $rules = (new Rules())->get_settings();
        if (empty($rules['cache_key_extra_onn']) || empty($rules['cache_key_extra_var'])) {
            return [];
        }

        $restricted = [
            // Standard headers that Cloudflare permits only with explicit value lists.
            'accept',
            'accept-charset',
            'accept-encoding',
            'accept-datetime',
            'accept-language',
            'referer',
            'user-agent',
            // Headers that re-implement cache or proxy behaviour.
            'connection',
            'content-length',
            'cache-control',
            'if-match',
            'if-modified-since',
            'if-none-match',
            'if-unmodified-since',
            'range',
            'upgrade',
            // Headers covered by other Cache Key features.
            'cookie',
            'host',
            'origin',
        ];

        $headers = [];
        foreach (explode(',', $rules['cache_key_extra_var']) as $var) {
            $var = trim($var);
            if ($var === '' || !str_starts_with($var, 'HTTP_')) {
                continue;
            }
            $header_name = Server::server_var_to_header_name($var);
            $lower_header = strtolower($header_name);

            if (str_starts_with($lower_header, 'cf-')) {
                $this->logger->warning('Skipping provider-specific header in cache_key_extra_var', [
                    'provider' => $this->get_provider_label(),
                    'header' => $header_name,
                ]);
                continue;
            }

            if (in_array($lower_header, $restricted, true)) {
                $this->logger->warning('Skipping restricted header in cache_key_extra_var', [
                    'provider' => $this->get_provider_label(),
                    'header' => $header_name,
                ]);
                continue;
            }
            $headers[] = $header_name;
        }

        return array_values(array_unique($headers));
    }

    /**
     * Build API URL
     *
     * @param string $endpoint API path.
     * @param array  $params Path segments.
     * @param array  $query Query string parameters.
     * @return string
     */
    protected function build_url(string $endpoint, array $params = [], array $query = []): string
    {
        $url = self::API_BASE_URL . $endpoint;

        if (!empty($params)) {
            $url .= '/' . implode('/', array_map('urlencode', $params));
        }

        if (!empty($query)) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * Make API request
     *
     * @param string     $method HTTP method.
     * @param string     $url Absolute endpoint URL.
     * @param array|null $data JSON body or null.
     * @return mixed
     */
    protected function make_request(string $method, string $url, ?array $data = null)
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($this->auth_type === 'key') {
            if ($this->account_email === '') {
                return new WP_Error('cloudflare_email_missing', 'Cloudflare account email is required for API key authentication');
            }

            $headers['X-Auth-Email'] = $this->account_email;
            $headers['X-Auth-Key'] = $this->api_token;
        } else {
            $headers['Authorization'] = 'Bearer ' . $this->api_token;
        }

        $args = [
            'method' => $method,
            'headers' => $headers,
        ];

        if ($data !== null) {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->logger->error("Failed to make {$method} request", [
                'provider' => $this->get_provider_label(),
                'url' => $url,
                'error' => $response->get_error_message(),
            ]);
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $code = wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            $error_message = 'Unknown error occurred';
            if (is_array($body) && isset($body['errors'][0]['message'])) {
                $error_message = $body['errors'][0]['message'];
            }

            return new WP_Error('cloudflare_api_error', $error_message);
        }

        return $response;
    }

    /**
     * Detect error messages caused by custom cache-key features the zone plan
     * does not allow. The caller then retries the rule install without them.
     *
     * @param string $message
     * @return bool
     */
    private function is_custom_cache_key_error(string $message): bool
    {
        $indicators = [
            'cache key',
            'custom_key',
            'custom cache',
            'not entitled',
            'entitlement',
            'not available on your plan',
            'enterprise',
        ];

        $lower = strtolower($message);
        foreach ($indicators as $indicator) {
            if (str_contains($lower, $indicator)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize API token
     *
     * @param string|null $token Raw credential to normalize.
     * @return string
     */
    public function normalize_api_token(?string $token): string
    {
        $token = trim( (string) $token);

        if (stripos($token, 'bearer ') === 0) {
            $token = substr($token, 7);
        }

        $token = preg_replace('/\s+/', '', $token);

        return $token ?: '';
    }
}
