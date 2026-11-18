<?php
/**
 * Self-hosted update channel plumbing.
 *
 * @package RunCache
 */

namespace RunCache\Support;

use stdClass;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Private update channel for RunCache.
 *
 * Supplies update data from the panel to the standard WordPress update flow:
 * - `pre_set_site_transient_update_plugins` drives the plugins.php notice,
 *   the update-core.php listing, WP-CLI updates, and the download/install.
 * - `plugins_api` drives the "View details" popup.
 *
 * WordPress core provides the whole upgrade UI and gates it behind its own
 * `update_plugins` capability checks. This class only supplies data.
 */
class Updater
{
    private const UPDATE_SLUG = 'runcache';
    private const VERSION_PATH = 'resources/runcache/latest_version';
    private const TRANSIENT_KEY = 'runcache_latestversion';
    private const TRANSIENT_TTL = 43200; // 12 hours

    /**
     * Structured logger.
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Initialize the updater with an optional logger override.
     *
     * @param Logger|null $logger Optional logger override.
     */
    public function __construct(
        ?Logger $logger = null
    ) {
        $this->logger = $logger ?? new Logger();
    }

    /**
     * Register the update hooks.
     *
     * @return void
     */
    public function init(): void
    {
        add_filter('plugins_api', [$this, 'plugins_api_filter'], 20, 3);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'update_plugins_filter']);
    }

    /**
     * Inject this plugin into the `update_plugins` site transient.
     *
     * The filter must always return a transient object.
     *
     * @param mixed $transient The value stored in the `update_plugins` transient.
     * @return object
     */
    public function update_plugins_filter($transient)
    {
        global $pagenow;

        if (!($transient instanceof stdClass)) {
            $transient = new stdClass();
            $transient->response = [];
        }

        // "Check again" on wp-admin/update-core.php bypasses the 12h cache.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cache-bypass flag.
        $force = (isset($pagenow) && 'update-core.php' === $pagenow) && absint(wp_unslash($_GET['force-check'] ?? 0)) === 1;

        $remote = $this->get_latest_version($force);

        if (null === $remote) {
            return $transient;
        }

        $installed = defined('RUNCACHE_VERSION') ? (string) RUNCACHE_VERSION : '';

        if ('' === $installed) {
            return $transient;
        }

        // Only offer an update when the remote version is newer.
        if (!version_compare($installed, $remote->version, '<')) {
            return $transient;
        }

        // Only offer an update when this WordPress is new enough.
        $requires = $this->string_value($remote, 'requires');
        if ('' !== $requires && !version_compare($requires, get_bloginfo('version'), '<')) {
            return $transient;
        }

        // Only offer an update when this PHP is new enough.
        $requires_php = $this->string_value($remote, 'requires_php');
        if ('' !== $requires_php && version_compare(PHP_VERSION, $requires_php, '<')) {
            return $transient;
        }

        $response = $this->build_response_row($remote);

        $transient->response[$response->plugin] = $response;

        return $transient;
    }

    /**
     * Answer the `plugin_information` API action ("View details" popup).
     *
     * Requests for other plugins pass through untouched.
     *
     * @param mixed $res    The default response, usually false.
     * @param mixed $action The requested API action.
     * @param mixed $args   API arguments; holds the plugin slug.
     * @return false|stdClass
     */
    public function plugins_api_filter($res, $action, $args)
    {
        // Pass jobs from other plugins through. Never block them.
        if (self::UPDATE_SLUG !== $this->requested_slug($args)) {
            return $res;
        }

        if ('plugin_information' !== $action) {
            return $res;
        }

        $remote = $this->get_latest_version();

        if (null === $remote) {
            return $res;
        }

        $details = new stdClass();
        $details->slug = self::UPDATE_SLUG;
        $details->name = $remote->name;
        $details->version = $remote->version;
        $details->download_link = $remote->download_url;
        $details->trunk = $remote->download_url;
        $details->tested = $this->string_value($remote, 'tested');
        $details->requires = $this->string_value($remote, 'requires');
        $details->requires_php = $this->string_value($remote, 'requires_php');
        $details->author = $this->string_value($remote, 'author');
        $details->author_profile = $this->string_value($remote, 'author_profile');
        $details->last_updated = $this->string_value($remote, 'last_updated');

        // Sections hold HTML and render inside the popup as shipped.
        $details->sections = [];
        foreach (['description', 'installation', 'changelog', 'screenshots'] as $section) {
            $content = $this->nested_string_value($remote, 'sections', $section);
            if ('' !== $content) {
                $details->sections[$section] = $content;
            }
        }

        $details->banners = [];
        foreach (['low', 'high'] as $size) {
            $banner = $this->nested_string_value($remote, 'banners', $size);
            if ('' !== $banner) {
                $details->banners[$size] = $banner;
            }
        }

        return $details;
    }

    /**
     * Fetch the latest version payload, with a 12h cache and stale fallback.
     *
     * @param bool $force True to bypass the cached payload.
     * @return stdClass|null Validated payload, or null when nothing is usable.
     */
    private function get_latest_version(bool $force = false): ?stdClass
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        $cached = is_string($cached) ? $cached : '';

        if ('' !== $cached && !$force) {
            return $this->validate_payload($cached);
        }

        $url = $this->build_version_url();

        if (null === $url) {
            // Failsafe: endpoint never configured by the build; stay silent.
            return null;
        }

        $response = wp_remote_get($url, [
            'timeout' => 5,
            'headers' => ['Accept' => 'application/json'],
            // Same value as the RunCloud Hub version check (update.md Section 4).
            // The download itself keeps WordPress upgrader defaults.
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            $this->logger->warning('Update check request failed', [
                'error' => $response->get_error_message(),
            ]);

            return $this->validate_payload($cached);
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if (200 !== $status || '' === $body) {
            $this->logger->warning('Update check returned an unexpected response', [
                'status' => $status,
            ]);

            return $this->validate_payload($cached);
        }

        $remote = $this->validate_payload($body);

        if (null === $remote) {
            // Do not cache a payload that fails the schema.
            $this->logger->warning('Update payload from the panel is invalid');

            return $this->validate_payload($cached);
        }

        set_transient(self::TRANSIENT_KEY, $body, self::TRANSIENT_TTL);

        return $remote;
    }

    /**
     * Build the full version endpoint URL.
     *
     * @return string|null Full URL, or null when the endpoint is unusable.
     */
    private function build_version_url(): ?string
    {
        $endpoint = defined('RUNCACHE_API_ENDPOINT') ? (string) RUNCACHE_API_ENDPOINT : '';

        if ('' === $endpoint) {
            return null;
        }

        $scheme = (string) wp_parse_url($endpoint, PHP_URL_SCHEME);
        $host = (string) wp_parse_url($endpoint, PHP_URL_HOST);

        if ('' === $host || !$this->is_allowed_scheme($scheme)) {
            return null;
        }

        return rtrim($endpoint, '/') . '/' . self::VERSION_PATH;
    }

    /**
     * Validate the remote JSON payload against the update contract.
     *
     * Required fields: name, version, download_url. Every other field is
     * treated as untrusted and read defensively at call sites.
     *
     * @param string $body Raw JSON body.
     * @return stdClass|null Decoded payload, or null when invalid.
     */
    private function validate_payload(string $body): ?stdClass
    {
        if ('' === $body) {
            return null;
        }

        $remote = json_decode($body);

        if (!($remote instanceof stdClass)) {
            return null;
        }

        if (!isset($remote->name, $remote->version, $remote->download_url)) {
            return null;
        }

        if (!is_string($remote->name)
            || !is_string($remote->version)
            || !is_string($remote->download_url)
            || '' === trim($remote->name)
            || '' === trim($remote->version)
            || '' === trim($remote->download_url)) {
            return null;
        }

        if (!$this->is_valid_package_url($remote->download_url)) {
            return null;
        }

        return $remote;
    }

    /**
     * Check that a package URL is safe for the upgrader to download.
     *
     * @param string $url Candidate package URL.
     * @return bool
     */
    private function is_valid_package_url(string $url): bool
    {
        $scheme = (string) wp_parse_url($url, PHP_URL_SCHEME);
        $host = (string) wp_parse_url($url, PHP_URL_HOST);

        return '' !== $host && $this->is_allowed_scheme($scheme);
    }

    /**
     * Allowed URL schemes.
     *
     * HTTPS is mandatory everywhere except under WP_DEBUG, where plain HTTP
     * keeps local panel testing possible.
     *
     * @param string $scheme URL scheme to check.
     * @return bool
     */
    private function is_allowed_scheme(string $scheme): bool
    {
        if ('https' === $scheme) {
            return true;
        }

        return 'http' === $scheme && defined('WP_DEBUG') && WP_DEBUG;
    }

    /**
     * Build the response row consumed by the WordPress upgrader.
     *
     * @param stdClass $remote Validated remote payload.
     * @return stdClass
     */
    private function build_response_row(stdClass $remote): stdClass
    {
        $plugin = defined('RUNCACHE_PLUGIN_BASENAME')
            ? (string) RUNCACHE_PLUGIN_BASENAME
            : self::UPDATE_SLUG . '/runcache.php';

        $res = new stdClass();
        $res->id = $this->id_value($remote);
        $res->slug = self::UPDATE_SLUG;
        $res->plugin = $plugin;
        $res->package = $remote->download_url;
        $res->new_version = $remote->version;
        $res->tested = $this->string_value($remote, 'tested');
        $res->url = $this->string_value($remote, 'url');

        $icons = [];
        foreach (['1x' => 'icons1x', '2x' => 'icons2x', 'svg' => 'iconssvg'] as $size => $key) {
            $icon = $this->string_value($remote, $key);
            if ('' !== $icon) {
                $icons[$size] = $icon;
            }
        }

        if ([] !== $icons) {
            $res->icons = $icons;
        }

        return $res;
    }

    /**
     * Read the plugin slug out of the plugins_api() arguments.
     *
     * @param mixed $args API arguments; object or array with a slug field.
     * @return string
     */
    private function requested_slug($args): string
    {
        if (is_object($args) && isset($args->slug)) {
            return is_string($args->slug) ? $args->slug : '';
        }

        if (is_array($args) && isset($args['slug'])) {
            return is_string($args['slug']) ? $args['slug'] : '';
        }

        return '';
    }

    /**
     * Read a string field from the payload; non-strings become ''.
     *
     * @param stdClass $data Payload object.
     * @param string   $key  Field name.
     * @return string
     */
    private function string_value(stdClass $data, string $key): string
    {
        $value = $data->$key ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Read the plugin id; the panel contract allows int or string.
     * Falls back to the slug when the value is missing or junk.
     *
     * @param stdClass $data Payload object.
     * @return int|string
     */
    private function id_value(stdClass $data): int|string
    {
        $value = $data->id ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && '' !== $value) {
            return $value;
        }

        return self::UPDATE_SLUG;
    }

    /**
     * Read a string field nested one level inside the payload. Handles
     * both object and array containers.
     *
     * @param stdClass $data     Payload object.
     * @param string   $key      Container field, e.g. 'sections'.
     * @param string   $sub_key  Field inside the container.
     * @return string
     */
    private function nested_string_value(stdClass $data, string $key, string $sub_key): string
    {
        $container = $data->$key ?? null;

        if (is_object($container)) {
            $value = $container->$sub_key ?? null;
        } elseif (is_array($container)) {
            $value = $container[$sub_key] ?? null;
        } else {
            $value = null;
        }

        return is_string($value) ? $value : '';
    }
}
