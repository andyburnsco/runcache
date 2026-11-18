<?php

namespace RunCache\Cli;

use Exception;
use RunCache\Integrations\Runcloud;
use RunCache\Core\MagicLink;
use RunCache\Support\Logger;
use RunCache\Support\Validator;
use RunCache\Core\Setting;
use WP_CLI;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RunCacheNative registers WP-CLI commands for RunCache native features
 *
 * This class provides CLI commands for:
 * - Setting configurations
 * - Sending settings to RunCloud
 * - Migrating from RunCloud Hub
 * - Managing magic users
 * - Managing magic links
 *
 * @package RunCache
 * @since 1.0.0
 */
class RunCacheNative
{
    /**
     * Magic link generator.
     *
     * @var MagicLink
     */
    private MagicLink $magic_link;

    private Runcloud $runcloud;

    /**
     * @param Logger|null  $logger
     * @param Setting|null $setting
     */
    public function __construct(
        ?Logger $logger = null,
        ?Setting $setting = null
    ) {
        $validator = new Validator();
        $this->magic_link = new MagicLink($logger, $validator, $setting);
        $this->runcloud = new Runcloud($logger, $setting);
    }

    /**
     * Initialize WP-CLI commands
     *
     * @since 1.0.0
     * @return void
     */
    public static function init(): void
    {
        if (defined('WP_CLI') && constant('WP_CLI')) {
            WP_CLI::add_command('runcache-native', self::class);
        }
    }

    /**
     * Set RunCache settings
     *
     * Configure RunCache settings via CLI. Accepts JSON data containing
     * cache_settings, cdn_settings, and object_cache_settings.
     *
     * ## OPTIONS
     *
     * [--file=<file>]
     * : Path to JSON file containing settings.
     *
     * ## EXAMPLES
     *
     *     wp runcache-native set-settings '{"cache_settings": {"enable": true}}'
     *     wp runcache-native set-settings < settings.json
     *     wp runcache-native set-settings --file=settings.json
     *
     * @when after_wp_load
     * @subcommand set-settings
     * @since 1.0.0
     */
    public function set_settings(array $args, array $assoc_args): void
    {
        $json_input = '';

        // Priority 1: JSON as positional argument
        if (!empty($args[0])) {
            $json_input = $args[0];
        }
        // Priority 2: Read from file
        elseif (!empty($assoc_args['file'])) {
            $file_path = $assoc_args['file'];
            if (!file_exists($file_path)) {
                WP_CLI::error("File not found: {$file_path}");
                return;
            }
            $json_input = file_get_contents($file_path);
            if ($json_input === false) {
                WP_CLI::error("Failed to read file: {$file_path}");
                return;
            }
        }
        // Priority 3: Read from stdin
        else {
            $stdin = fopen('php://stdin', 'r');
            if ($stdin) {
                $json_input = stream_get_contents($stdin);
                fclose($stdin);
            }
        }

        if (empty($json_input)) {
            WP_CLI::error('No settings data provided. Pass JSON as argument, via stdin, or --file option.');
            return;
        }

        // Parse JSON
        $data = json_decode($json_input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            WP_CLI::error('Invalid JSON: ' . json_last_error_msg());
            return;
        }

        // Validate structure
        if (!is_array($data)) {
            WP_CLI::error('Invalid settings format: expected JSON object');
            return;
        }

        try {
            unset($data['runcloud']);
            $result = $this->runcloud->import_settings_cli($data);

            if ($result instanceof WP_Error) {
                throw new Exception($result->get_error_message());
            }

            // Report results
            foreach ($result['messages'] as $message) {
                WP_CLI::line($message);
            }

            if ($result['saved']) {
                WP_CLI::success('Settings imported successfully');
            } else {
                WP_CLI::error('Some settings failed to save');
            }
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Get RunCache settings
     *
     * Display current RunCache settings including cache, CDN, and object cache settings.
     * The output mirrors the structure used by set-settings command.
     *
     * ## EXAMPLES
     *
     *     wp runcache-native get-settings
     *
     *     # Get settings and save to file
     *     wp runcache-native get-settings > settings.json
     *
     * @when after_wp_load
     * @subcommand get-settings
     * @since 1.0.0
     */
    public function get_settings(): void
    {
        try {
            $page_cache = new \RunCache\Core\PageCache();
            $cdn = new \RunCache\Core\Cdn();
            $edge = new \RunCache\Core\Edge();
            $object_cache = new \RunCache\Core\ObjectCache();

            $runcloud_settings = $this->runcloud->get_settings();

            $output = [
                'cache_settings' => $page_cache->get_settings(),
                'cdn_settings' => $cdn->get_settings(),
                'edge_settings' => $edge->get_settings(),
                'object_cache_settings' => $object_cache->get_settings(),
            ];

            // Only include runcloud_settings if cache_type is set
            if (!empty($runcloud_settings['cache_type'])) {
                $output['runcloud_settings'] = [
                    'cache_type' => $runcloud_settings['cache_type'],
                ];
            }

            WP_CLI::line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (Exception $e) {
            WP_CLI::error('Failed to get settings: ' . $e->getMessage());
        }
    }

    /**
     * Send settings to RunCloud
     *
     * Push current RunCache settings to RunCloud Hub.
     *
     * ## EXAMPLES
     *
     *     wp runcache-native send-settings
     *
     * @when after_wp_load
     * @subcommand send-settings
     * @since 1.0.0
     */
    public function send_settings(): void
    {
        try {
            $response = $this->runcloud->sync_settings();

            if ($response instanceof WP_Error) {
                throw new Exception($response->get_error_message());
            }

            WP_CLI::success('Settings pushed to RunCloud Hub successfully');
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Enable RunCloud cache
     *
     * Enable RunCloud server cache (native or SRCache). This command enables
     * either RunCloud Server Cache or RunCloud Server Cache (SRCache).
     *
     * ## OPTIONS
     *
     * <cache_type>
     * : Cache type to enable
     * ---
     * options:
     *   - native
     *   - sr
     * ---
     *
     * [--blog-id=<id>]
     * : Specify a blog/site ID to enable cache for (required for multisite)
     *
     * [--network]
     * : Enable cache for all sites in the multisite network (mutually exclusive with --blog-id)
     *
     * ## EXAMPLES
     *
     *     wp runcache-native enable native
     *     wp runcache-native enable sr
     *     wp runcache-native enable native --blog-id=1
     *     wp runcache-native enable native --network
     *     wp runcache-native enable sr --network
     *
     * @when after_wp_load
     * @subcommand enable
     * @since 1.0.0
     */
    public function enable(array $args, array $assoc_args = []): void
    {
        $blog_id = null;
        $switched = false;

        // Validate cache type argument first
        if (count($args) < 1) {
            WP_CLI::error('Usage: wp runcache-native enable <native|sr>');
            return;
        }

        $cache_type = strtolower($args[0]);

        // Validate cache type
        if (!in_array($cache_type, ['native', 'sr'], true)) {
            WP_CLI::error('Invalid cache type. Valid options are: native, sr');
            WP_CLI::line('  native - RunCloud Server Cache');
            WP_CLI::line('  sr     - RunCloud Server Cache (SRCache)');
            return;
        }

        $use_network = isset($assoc_args['network']);
        $use_blog_id = isset($assoc_args['blog-id']);

        // Validate that --network and --blog-id are mutually exclusive
        if ($use_network && $use_blog_id) {
            WP_CLI::error('--network and --blog-id cannot be used together. Please use one or the other.');
            return;
        }

        // Map to actual cache type values
        $page_cache_type = ($cache_type === 'sr') ? 'runcloud-srcache' : 'runcloud-native';

        // Handle network-wide enable for multisite
        if ($use_network) {
            if (!is_multisite()) {
                WP_CLI::error('The --network flag can only be used on multisite installations.');
                return;
            }

            $this->enable_network($cache_type, $page_cache_type);
            return;
        }

        // Check for multisite and require blog-id for single site
        if (is_multisite()) {
            $blog_id = $use_blog_id ? absint($assoc_args['blog-id']) : null;
            if (empty($blog_id)) {
                WP_CLI::error('This is a multisite installation. Please specify a blog ID using --blog-id=<id> or use --network to enable for all sites.');
                return;
            }
            switch_to_blog($blog_id);
            $switched = true;
        }

        try {
            $page_cache = new \RunCache\Core\PageCache();

            WP_CLI::line('Enabling RunCloud ' . ($cache_type === 'sr' ? 'Server Cache (SRCache)' : 'Server Cache') . '...');

            $settings = [
                'enable' => true,
                'page_cache_type' => $page_cache_type,
            ];

            $result = $page_cache->save_settings($settings);

            if (!$result) {
                throw new Exception('Failed to save settings');
            }

            // Verify the cache
            $verification = $page_cache->verify_cache();

            if ($switched) {
                restore_current_blog();
            }

            if ($verification['verified']) {
                WP_CLI::success(ucfirst($cache_type) . ' cache enabled and verified successfully');
            } else {
                WP_CLI::warning('Cache enabled but verification pending');
                WP_CLI::line('  Message: ' . ($verification['message'] ?? 'N/A'));
            }

            WP_CLI::line('');
            WP_CLI::line('Cache type: ' . $page_cache_type);
            WP_CLI::line('Enabled: true');

        } catch (Exception $e) {
            if ($switched) {
                restore_current_blog();
            }
            WP_CLI::error('Failed to enable RunCloud cache: ' . $e->getMessage());
        }
    }

    /**
     * Enable cache for all sites in a multisite network
     *
     * @param string $cache_type      The cache type (native or sr)
     * @param string $page_cache_type The internal page cache type value
     * @return void
     */
    private function enable_network(string $cache_type, string $page_cache_type): void
    {
        $site_ids = get_sites(['fields' => 'ids']);

        if (empty($site_ids)) {
            WP_CLI::warning('No sites found in the network.');
            return;
        }

        $total_sites = count($site_ids);
        $success_count = 0;
        $failed_sites = [];

        WP_CLI::line(sprintf('Enabling RunCloud %s for %d site(s)...', ($cache_type === 'sr' ? 'Server Cache (SRCache)' : 'Server Cache'), $total_sites));

        foreach ($site_ids as $site_id) {
            $switched = false;

            try {
                switch_to_blog($site_id);
                $switched = true;

                $page_cache = new \RunCache\Core\PageCache();

                $settings = [
                    'enable' => true,
                    'page_cache_type' => $page_cache_type,
                ];

                $result = $page_cache->save_settings($settings);

                if (!$result) {
                    throw new Exception('Failed to save settings');
                }

                // Verify the cache
                $verification = $page_cache->verify_cache();

                if ($switched) {
                    restore_current_blog();
                }

                $success_count++;

                if ($verification['verified']) {
                    WP_CLI::success(sprintf('Site %d: %s cache enabled and verified', $site_id, ucfirst($cache_type)));
                } else {
                    WP_CLI::warning(sprintf('Site %d: %s cache enabled but verification pending', $site_id, ucfirst($cache_type)));
                }

            } catch (Exception $e) {
                if ($switched) {
                    restore_current_blog();
                }

                $failed_sites[] = $site_id;
                WP_CLI::warning(sprintf('Site %d: Failed to enable cache - %s', $site_id, $e->getMessage()));
                continue;
            }
        }

        WP_CLI::line('');
        WP_CLI::line(sprintf('Enabled: %s', $page_cache_type));
        WP_CLI::line(sprintf('Total sites processed: %d', $total_sites));
        WP_CLI::line(sprintf('Successfully enabled: %d', $success_count));

        if (!empty($failed_sites)) {
            WP_CLI::warning(sprintf('Failed on %d site(s): %s', count($failed_sites), implode(', ', $failed_sites)));
        } else {
            WP_CLI::success('All sites enabled successfully');
        }
    }

    /**
     * Migrate configuration from RunCloud Hub to RunCache, including Cloudflare.
     * On multisite, run per site with --blog-id or --url.
     *
     * ## OPTIONS
     *
     * [--no-enable-cloudflare]
     * : Save the Cloudflare credentials inactive even when all checks pass.
     *
     * [--re-detect-zone]
     * : Re-detect the Cloudflare zone via the API when the backup has no zone ID.
     *
     * [--cf-provider=<auto|cloudflare|cloudflare-apo>]
     * : Override the automatic Cloudflare provider detection. Default: auto.
     *
     * [--keep-page-rule]
     * : Keep the legacy page rule even after the new cache rule installs.
     *
     * ## EXAMPLES
     *
     *     wp runcache-native migrate-runcloudhub
     *     wp runcache-native migrate-runcloudhub --no-enable-cloudflare
     *     wp runcache-native migrate-runcloudhub --re-detect-zone
     *     wp runcache-native migrate-runcloudhub --cf-provider=cloudflare
     *
     * @when after_wp_load
     * @subcommand migrate-runcloudhub
     * @since 1.0.0
     */
    public function migrate_runcloudhub(array $args = [], array $assoc_args = []): void
    {
        $cf_provider = 'auto';
        if (isset($assoc_args['cf-provider'])) {
            $cf_provider = (string) $assoc_args['cf-provider'];
            if (!in_array($cf_provider, ['auto', 'cloudflare', 'cloudflare-apo'], true)) {
                WP_CLI::error('Invalid --cf-provider value. Valid options are: auto, cloudflare, cloudflare-apo');
                return;
            }
        }

        try {
            $result = $this->runcloud->restore_runcloudhub_config();

            if ($result instanceof WP_Error) {
                throw new Exception($result->get_error_message());
            }

            $restored_settings = $result['restored_settings'] ?? [];
            $unused_keys = $result['unused_keys'] ?? [];

            if (!empty($restored_settings)) {
                WP_CLI::line('Settings restored from backup:');
                WP_CLI::line(json_encode($restored_settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            if (!empty($unused_keys)) {
                WP_CLI::line('Unused backup keys:');
                WP_CLI::line(json_encode($unused_keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                WP_CLI::line('All backup keys were used');
            }

            // Cloudflare part. Runs after Part A; a Cloudflare failure never
            // aborts the purger/preloader/rules restore above.
            $cf_result = $this->runcloud->restore_cloudflare_config([
                'no_enable' => isset($assoc_args['no-enable-cloudflare']),
                're_detect_zone' => isset($assoc_args['re-detect-zone']),
                'cf_provider' => $cf_provider,
                'keep_page_rule' => isset($assoc_args['keep-page-rule']),
            ]);
            $this->print_cloudflare_report($cf_result);

            WP_CLI::success('Configuration migrated from RunCloud Hub successfully');
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Print the Cloudflare migration result.
     * One outcome line, a page-rule note when the rule survives, and warnings.
     *
     * @param array|WP_Error $result Migration result payload or failure.
     * @return void
     */
    private function print_cloudflare_report(array|WP_Error $result): void
    {
        if ($result instanceof WP_Error) {
            WP_CLI::warning('Cloudflare migration failed: ' . $result->get_error_message());
            return;
        }

        if (empty($result['found'])) {
            WP_CLI::line('Cloudflare: no configuration found in backup — skipping.');
            return;
        }

        $provider = (string) ($result['provider'] ?? '');
        $rule_mode = (string) ($result['rule_mode'] ?? '');
        $detail = trim( (string) ($result['status_detail'] ?? ''));
        $provider_suffix = '' !== $provider ? ' (' . $provider . ')' : '';

        if (!empty($result['enabled'])) {
            $message = 'Cloudflare enabled' . $provider_suffix;
            $message .= 'page_rule' === $rule_mode ? ', page-rule mode' : '';
            $message .= '' !== $detail ? ' — ' . $detail : '';
            WP_CLI::success($message);
        } else {
            $message = 'Cloudflare ' . (empty($result['saved']) ? 'not saved' : 'saved inactive') . $provider_suffix;
            $message .= '' !== $detail ? ' — ' . $detail : '';
            WP_CLI::line($message);
        }

        // Print the page-rule note only when the rule survived.
        $page_rule_state = (string) ($result['page_rule_state'] ?? 'none');
        if (in_array($page_rule_state, ['kept_adopted', 'kept_inactive', 'unknown'], true)) {
            WP_CLI::line('Cloudflare old page rule: ' . $this->describe_cf_page_rule_state($page_rule_state));
        }

        $warnings = array_values(array_filter( (array) ($result['warnings'] ?? [])));
        foreach ($warnings as $warning) {
            WP_CLI::warning($warning);
        }
    }

    /**
     * Human text for the page_rule_state code in the CLI report.
     *
     * @param string $state Page-rule state code.
     * @return string
     */
    private function describe_cf_page_rule_state(string $state): string
    {
        return match ($state) {
            'removed' => 'removed after confirmation',
            'kept_adopted' => 'kept (active mechanism)',
            'kept_inactive' => 'kept (--keep-page-rule)',
            'unknown' => 'unknown — check the Cloudflare dashboard',
            default => 'not touched',
        };
    }

    /**
     * List magic users
     *
     * Display users available for magic link authentication.
     *
     * ## EXAMPLES
     *
     *     wp runcache-native magic-users
     *
     * @when after_wp_load
     * @subcommand magic-users
     * @since 1.0.0
     */
    public function magic_users(): void
    {
        try {
            $users = $this->magic_link->get();
            WP_CLI::line(wp_json_encode($users, JSON_UNESCAPED_SLASHES));
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Generate magic links
     *
     * Create magic authentication links for user login.
     *
     * ## OPTIONS
     *
     * <user_id>
     * : The user ID to generate a magic link for.
     *
     * [<blog_id>]
     * : Optional. The blog ID for multisite installations.
     *
     * ## EXAMPLES
     *
     *     wp runcache-native magic-links 1
     *     wp runcache-native magic-links 1 2
     *
     * @param array $args Positional arguments: user id and optional blog id.
     * @when after_wp_load
     * @subcommand magic-links
     * @since 1.0.0
     */
    public function magic_links(array $args): void
    {
        if (count($args) < 1) {
            WP_CLI::error('Usage: wp runcache-native magic-links <user_id> [blog_id]');
            return;
        }

        $switched = false;
        $blog_id = get_current_blog_id();

        try {
            $user_id = absint($args[0]);

            // Optional blog_id argument for multisite.
            if (isset($args[1])) {
                $blog_id = absint($args[1]);

                if (is_multisite()) {
                    switch_to_blog($blog_id);
                    $switched = true;
                } elseif (get_current_blog_id() !== $blog_id) {
                    WP_CLI::error('blog_id does not match with current blog id');
                    return;
                }
            }

            if ($switched) {
                restore_current_blog();
            }

            $result = $this->magic_link->generate($user_id, $blog_id);

            if (false === $result) {
                WP_CLI::error('Failed to generate magic link');
                return;
            }

            WP_CLI::success('Magic link generated successfully');
            WP_CLI::line(wp_json_encode($result, JSON_UNESCAPED_SLASHES));
        } catch (Exception $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    /**
     * Set RunCloud API credentials
     *
     * Configure RunCloud API credentials for cache integration. These credentials
     * are required to enable RunCloud cache types (runcloud and runcloud-srcache).
     *
     * ## OPTIONS
     *
     * <rcapi_key>
     * : RunCloud API Key
     *
     * <rcapi_secret>
     * : RunCloud API Secret
     *
     * <webapp_id>
     * : RunCloud WebApp ID
     *
     * ## EXAMPLES
     *
     *     wp runcache-native set-api "your-api-key" "your-api-secret" "12345"
     *
     * @param array $args Positional arguments: API key, secret, and WebApp id.
     * @param array $assoc_args Associative arguments (unused).
     * @when after_wp_load
     * @subcommand set-api
     * @since 1.0.0
     */
    public function set_credentials(array $args, array $assoc_args): void
    {
        if (count($args) < 3) {
            WP_CLI::error('Usage: wp runcache-native set-api <rcapi_key> <rcapi_secret> <webapp_id>');
            return;
        }

        $rcapi_key = $args[0];
        $rcapi_secret = $args[1];
        $webapp_id = $args[2];

        // Validate required parameters
        if (empty($rcapi_key) || empty($rcapi_secret) || empty($webapp_id)) {
            WP_CLI::error('All parameters are required: rcapi_key, rcapi_secret, and webapp_id');
            return;
        }

        try {
            WP_CLI::line('Setting RunCloud API credentials...');
            WP_CLI::line('  API Key: ' . substr($rcapi_key, 0, 8) . '...');
            WP_CLI::line('  API Secret: ' . substr($rcapi_secret, 0, 8) . '...');
            WP_CLI::line('  WebApp ID: ' . $webapp_id);

            // Use default cache type (native) - can be changed via admin or wp runcache-native enable
            $result = $this->runcloud->save_runcloud_info('native', $rcapi_key, $rcapi_secret, $webapp_id);

            if ($result instanceof WP_Error) {
                throw new Exception($result->get_error_message());
            }

            WP_CLI::success('RunCloud API credentials saved successfully');
            WP_CLI::line('');
            WP_CLI::line('Next steps:');
            WP_CLI::line('  1. Run: wp runcache-native enable native   (for RunCloud Server Cache)');
            WP_CLI::line('     wp runcache-native enable sr          (for RunCloud SRCache)');
            WP_CLI::line('  2. Or select RunCloud Server Cache from the WordPress admin panel');
        } catch (Exception $e) {
            WP_CLI::error('Failed to save RunCloud credentials: ' . $e->getMessage());
        }
    }
}
