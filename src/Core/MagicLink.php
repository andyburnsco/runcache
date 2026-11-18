<?php
/**
 * Magic link login for RunCache.
 *
 * Generates signed, expiring login URLs for administrators and
 * authenticates users who follow them.
 *
 * @package RunCache
 */

namespace RunCache\Core;

use Exception;
use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * MagicLink class handles magic link generation and authentication
 */
class MagicLink
{
    /**
     * Query-string key that carries the signed token.
     *
     * @var string
     */
    private string $magictoken = '__rcmagictoken';

    /**
     * Query-string key that carries the user id.
     *
     * @var string
     */
    private string $magicuserid = '__rcmagicuserid';

    /**
     * Query-string key that carries the expiry timestamp.
     *
     * @var string
     */
    private string $magicexpiry = '__rcmagicexpiry';

    /**
     * Structured logger.
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Settings store.
     *
     * @var Setting
     */
    private Setting $setting;

    /**
     * Input validator.
     *
     * @phpstan-ignore property.onlyWritten
     * @var Validator
     */
    private Validator $validator;

    /**
     * Default magic link settings.
     *
     * @var array<string, bool|int>
     */
    private array $default_settings = [
        'enable' => false,
        'magiclink_expiry' => 300,
    ];

    /**
     * Initialize the module with optional dependency overrides.
     *
     * @param Logger|null    $logger Optional logger override.
     * @param Validator|null $validator Optional validator override.
     * @param Setting|null   $setting Optional settings store override.
     */
    public function __construct(
        ?Logger $logger = null,
        ?Validator $validator = null,
        ?Setting $setting = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->validator = $validator ?? new Validator();
        $this->setting = $setting ?? new Setting();
    }

    /**
     * Initialize magic link functionality
     *
     * @return void
     */
    public function init(): void
    {
        add_action('init', [$this, 'callback_magiclink_login']);
    }

    /**
     * Get magic link settings
     *
     * @return array
     */
    public function get_settings(): array
    {
        $settings = wp_parse_args($this->setting->get('magiclink'), $this->default_settings);
        return apply_filters('runcache_magiclink_settings', $settings);
    }

    /**
     * Save magic link settings
     *
     * @param array $settings New magic link settings.
     * @return bool
     */
    public function save_settings(array $settings): bool
    {
        try {
            $settings = wp_parse_args($settings, $this->default_settings);

            $validated = [];
            $validated['enable'] = isset($settings['enable'])
                ? (bool) $settings['enable']
                : $this->default_settings['enable'];
            $validated['magiclink_expiry'] = isset($settings['magiclink_expiry'])
                ? absint($settings['magiclink_expiry'])
                : $this->default_settings['magiclink_expiry'];

            $result = $this->setting->set('magiclink', $validated);

            if ($result) {
                $this->logger->info('Settings saved successfully', ['component' => 'MagicLink']);
            } else {
                $this->logger->error('Failed to save settings', ['component' => 'MagicLink']);
            }

            return $result;
        } catch (Exception $e) {
            $this->logger->error('Error saving settings', ['component' => 'MagicLink', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Get eligible users for magic link
     *
     * @return array
     */
    public function get(): array
    {
        try {
            $settings = $this->get_settings();
            $magiclink_enabled = !empty($settings['enable']);
            $blog_id = get_current_blog_id();
            $results = [];

            if (!is_multisite()) {
                $users = get_users([
                    'role__in' => ['administrator'],
                    'order' => 'ASC',
                ]);

                foreach ($users as $user) {
                    if (!empty($user->data->deleted)) {
                        continue;
                    }

                    $results[] = [
                        'magiclink' => $magiclink_enabled,
                        'blog_id' => $blog_id,
                        'user_id' => $user->data->ID,
                        'user_login' => $user->data->user_login,
                        'user_email' => $user->data->user_email,
                        'user_role' => implode(',', $user->roles),
                        'url' => get_site_url(),
                        'domain' => wp_parse_url(get_site_url(), PHP_URL_HOST)
                    ];
                }
            } else {
                $super_admins = get_super_admins();

                foreach ($super_admins as $super_admin) {
                    $user = get_user_by('login', $super_admin);

                    if (!empty($user->data->deleted)) {
                        continue;
                    }

                    $results[] = [
                        'magiclink' => $magiclink_enabled,
                        'blog_id' => $blog_id,
                        'user_id' => $user->data->ID,
                        'user_login' => $user->data->user_login,
                        'user_email' => $user->data->user_email,
                        'user_role' => 'super_administrator',
                        'url' => get_site_url(),
                        'domain' => wp_parse_url(get_site_url(), PHP_URL_HOST)
                    ];
                }
            }

            return $results;
        } catch (Exception $e) {
            $this->logger->error('Error getting magic link users: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Remove magic link tokens for all users
     *
     * @return void
     */
    public function remove(): void
    {
        try {
            $users = $this->get();
            foreach ($users as $user) {
                delete_user_meta($user['user_id'], 'magic_link_token');
            }
            $this->logger->info('Tokens removed successfully', ['component' => 'MagicLink']);
        } catch (Exception $e) {
            $this->logger->error('Error removing tokens', ['component' => 'MagicLink', 'error' => $e->getMessage()]);
        }
    }

    /**
     * Generate magic link for a user
     *
     * @param int $user_id User to generate the link for.
     * @param int $blog_id Site the link is scoped to.
     * @return array|false
     */
    public function generate(int $user_id, int $blog_id): array|false
    {
        try {
            $settings = $this->get_settings();
            $expiry = time() + $settings['magiclink_expiry'];
            $site_url = get_site_url($blog_id, '/');
            $domain = wp_parse_url($site_url, PHP_URL_HOST);

            $salt = wp_salt('auth');
            $selector = wp_generate_password(16, false, false);
            $verifier = wp_json_encode([
                $selector,
                $domain,
                (int) $user_id,
                (int) $expiry
            ]);
            $validator = hash_hmac('sha256', $verifier, $salt);

            $url = add_query_arg([
                $this->magicuserid => $user_id,
                $this->magicexpiry => $expiry,
                $this->magictoken => $selector . '|' . $validator
            ], $site_url);

            $token_data = [
                'token' => $selector,
                'expiry' => $expiry,
                'expiry_h' => gmdate('Y-m-d H:i:s', $expiry) . ' UTC',
            ];

            update_user_meta($user_id, 'magic_link_token', $token_data);

            $this->logger->info('Generated successfully', [
                'component' => 'MagicLink',
                'user_id' => $user_id,
                'expiry' => $token_data['expiry_h']
            ]);

            return [
                'url' => $url,
                'expiry' => $token_data['expiry_h']
            ];
        } catch (Exception $e) {
            $this->logger->error('Error generating link', ['component' => 'MagicLink', 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Handle magic link login callback
     *
     * Magic links arrive from signed email URLs. No nonce can exist for
     * an unauthenticated login request, so superglobal reads below are
     * intentionally exempt from the nonce sniff.
     *
     * @return void
     * @throws Exception When link parameters are missing or the token is invalid, expired, or fails the signature check.
     */
    public function callback_magiclink_login(): void
    {
        try {
            if (is_admin()) {
                return;
            }

            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Signed unauthenticated login URL; see docblock.
            if (!isset($_GET[$this->magicuserid]) || !isset($_GET[$this->magicexpiry]) || !isset($_GET[$this->magictoken])) {
                return;
            }

            $user_id = absint($_GET[$this->magicuserid]);
            $expiry = absint($_GET[$this->magicexpiry]);
            $token = sanitize_text_field(wp_unslash($_GET[$this->magictoken]));
            // phpcs:enable

            if (empty($user_id) || empty($expiry) || empty($token)) {
                throw new Exception('Invalid magic link parameters');
            }

            if (strpos($token, '|') === false) {
                throw new Exception('Invalid magic link token format');
            }

            [$selector, $validator] = explode('|', $token);
            if (empty($selector) || empty($validator)) {
                throw new Exception('Invalid magic link token parts');
            }

            $token_data = get_user_meta($user_id, 'magic_link_token', true);
            if (empty($token_data) || !is_array($token_data)) {
                throw new Exception('Magic link token not found');
            }

            if ($selector !== $token_data['token']) {
                throw new Exception('Invalid magic link token');
            }

            if ($expiry !== $token_data['expiry']) {
                throw new Exception('Invalid magic link expiry');
            }

            if (time() >= $token_data['expiry']) {
                delete_user_meta($user_id, 'magic_link_token');
                throw new Exception('Magic link has expired');
            }

            $salt = wp_salt('auth');
            $site_url = site_url('/');
            $domain = wp_parse_url($site_url, PHP_URL_HOST);
            $verifier = wp_json_encode([$selector, $domain, (int) $user_id, (int) $expiry]);
            $validator_expected = hash_hmac('sha256', $verifier, $salt);

            if (!hash_equals($validator, $validator_expected)) {
                throw new Exception('Invalid magic link signature');
            }

            $user = get_user_by('id', $user_id);
            if (!$user) {
                throw new Exception('User not found');
            }

            wp_set_auth_cookie($user->ID, false);
            do_action('wp_login', $user->user_login, $user);

            delete_user_meta($user_id, 'magic_link_token');

            $redirect_url = is_multisite() && is_super_admin($user_id)
                ? network_admin_url('/')
                : admin_url('/');

            wp_safe_redirect($redirect_url);
            exit;
        } catch (Exception $e) {
            $this->logger->error('Login failed', ['component' => 'MagicLink', 'error' => $e->getMessage()]);
            wp_die(
                esc_html__('This magic link is invalid or has expired.', 'runcache'),
                esc_html__('Magic Link Error', 'runcache')
            );
        }
    }
}
