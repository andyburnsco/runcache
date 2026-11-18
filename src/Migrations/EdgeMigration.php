<?php
/**
 * One-time edge cache migration from legacy settings.
 *
 * @package RunCache
 */

namespace RunCache\Migrations;

use RunCache\Core\Edge;
use RunCache\Core\Setting;
use RunCache\Support\Logger;
use RunCache\Support\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One-time, upgrade-gated migration of legacy Cloudflare settings into the
 * new CDN Edge Cache settings model.
 *
 * Context
 * -------
 * Before the CDN Edge Cache model existed, the two Cloudflare edge providers
 * were stored in separate legacy structures:
 *
 *   Cloudflare Cache  -> active state in `runcache_page_cache_settings`
 *                        (`enable` + `page_cache_type === 'cloudflare'`),
 *                        credentials in `runcache_cloudflare_settings`.
 *   Cloudflare APO    -> active state in `runcache_cdn_settings`
 *                        (`enable` + `cdn_type === 'cloudflare-apo'`),
 *                        credentials in `runcache_cdn_settings.provider.cloudflare-apo`.
 *
 * The new model stores both under the single `runcache_edge_settings` option
 * owned by {@see \RunCache\Core\Edge}.
 *
 * Behaviour
 * ---------
 * - Pure data copy: it mirrors the legacy ACTIVE/DISABLED state exactly and
 *   never auto-enables a provider.
 * - Credentials are imported whenever a non-empty legacy credential record
 *   exists, regardless of whether the provider was active or disabled.
 * - New-format data always wins: if the destination already contains edge
 *   provider data, the whole migration is skipped so existing configuration is
 *   never recomputed from legacy state.
 * - Silent: it writes the edge option directly and fires no hooks, makes no
 *   Cloudflare API calls, and never touches the legacy source options.
 *
 * The routine is gated on a migration version (`RUNCACHE_DB_VERSION`) and runs
 * once per site on `admin_init` when the stored `runcache_db_version` is older.
 */
class EdgeMigration
{
    public const DB_VERSION_OPTION = 'runcache_db_version';

    /**
     * Structured logger.
     *
     * @var Logger
     */
    protected Logger $logger;

    /**
     * Settings store.
     *
     * @var Setting
     */
    protected Setting $setting;

    /**
     * Initialize the migration with optional dependency overrides.
     *
     * @param Logger|null    $logger Optional logger override.
     * @param Validator|null $validator Unused here; accepted to match the
     *                                  core-module constructor signature used by
     *                                  RunCache::load_modules().
     * @param Setting|null   $setting Optional settings store override.
     */
    public function __construct(
        ?Logger $logger = null,
        ?Validator $validator = null,
        ?Setting $setting = null
    ) {
        $this->logger = $logger ?? new Logger();
        $this->setting = $setting ?? new Setting();
    }

    /**
     * Register the migration check.
     *
     * @return void
     */
    public function init(): void
    {
        add_action('admin_init', [$this, 'maybe_migrate']);
    }

    /**
     * Run the pending migration step(s) if the installed plugin version is
     * ahead of the applied database version.
     *
     * @return void
     */
    public function maybe_migrate(): void
    {
        // Multisite: runcache_* options are per-site, and admin_init fires per
        // site, so each site migrates independently on its own admin visit.
        $installed = (string) (defined('RUNCACHE_DB_VERSION') ? RUNCACHE_DB_VERSION : '1');
        $applied = (string) get_option(self::DB_VERSION_OPTION, '0');

        if (version_compare($installed, $applied, '<=')) {
            return; // Already migrated (or a downgrade).
        }

        $this->migrate();

        // Record the applied version regardless of individual step success so
        // an already-migrated site is not reprocessed on every admin request.
        update_option(self::DB_VERSION_OPTION, $installed);
    }

    /**
     * Migrate legacy Cloudflare settings into the new edge model.
     *
     * @return bool True if a write occurred, false if nothing to do/failed.
     */
    public function migrate(): bool
    {
        try {
            $destination = $this->setting->get(Edge::OPTION_KEY, []);
            $destination = is_array($destination) ? $destination : [];

            // If the destination is already new-format with provider data, treat
            // the existing document as authoritative and leave it untouched. This
            // protects anyone who already migrated/configured edge caching from
            // having their active state recomputed from legacy data.
            if (isset($destination['provider']) && is_array($destination['provider']) && [] !== $destination['provider']) {
                $this->logger->info(
                    'Edge migration: destination already contains edge provider data; leaving it untouched',
                    ['migration' => 'edge']
                );
                return false;
            }

            $result = [
                'enable' => false,
                'edge_type' => '',
                'provider' => [],
            ];

            $cf_active = $this->is_cloudflare_active();
            $apo_active = $this->is_apo_active();

            $imported_cf = $this->import_cloudflare($result);
            $imported_apo = $this->import_apo($result);

            // Derive the new active state strictly from the legacy active flags.
            if ($cf_active && $apo_active) {
                // Both legacy providers claimed active. The new model allows only
                // one edge_type; Cloudflare APO wins. Both credential records are
                // still stored so the user can switch.
                $result['enable'] = true;
                $result['edge_type'] = Edge::CLOUDFLARE_APO;
                $this->logger->warning(
                    'Edge migration: both legacy Cloudflare and Cloudflare APO were active; using Cloudflare APO as the active edge provider',
                    ['migration' => 'edge']
                );
            } elseif ($cf_active) {
                $result['enable'] = true;
                $result['edge_type'] = Edge::CLOUDFLARE;
            } elseif ($apo_active) {
                $result['enable'] = true;
                $result['edge_type'] = Edge::CLOUDFLARE_APO;
            }

            // No-op fast path: nothing was imported, so there is nothing to write.
            if (!$imported_cf && !$imported_apo) {
                $this->logger->info(
                    'Edge migration: no legacy Cloudflare data found; nothing to migrate',
                    ['migration' => 'edge']
                );
                return false;
            }

            return $this->setting->set(Edge::OPTION_KEY, $result);
        } catch (\Exception $exception) {
            $this->logger->error('Edge migration failed', [
                'migration' => 'edge',
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Whether legacy Cloudflare Cache was the active page-cache provider.
     *
     * @return bool
     */
    protected function is_cloudflare_active(): bool
    {
        $page = $this->setting->get('page_cache', []);
        $page = is_array($page) ? $page : [];

        return !empty($page['enable'])
            && ($page['page_cache_type'] ?? '') === Edge::CLOUDFLARE;
    }

    /**
     * Whether legacy Cloudflare APO was the active CDN provider.
     *
     * @return bool
     */
    protected function is_apo_active(): bool
    {
        $cdn = $this->setting->get('cdn', []);
        $cdn = is_array($cdn) ? $cdn : [];

        return !empty($cdn['enable'])
            && ($cdn['cdn_type'] ?? '') === Edge::CLOUDFLARE_APO;
    }

    /**
     * Import legacy Cloudflare Cache credentials into the destination.
     *
     * New-format data for the provider wins: if a non-empty entry already
     * exists in the destination, the legacy credentials are skipped.
     *
     * @param array $result Migration result being built (by reference).
     * @return bool True if a provider record was written.
     */
    protected function import_cloudflare(array &$result): bool
    {
        // New data wins.
        if (isset($result['provider'][Edge::CLOUDFLARE])
            && is_array($result['provider'][Edge::CLOUDFLARE])
            && [] !== $result['provider'][Edge::CLOUDFLARE]) {
            return false;
        }

        $legacy = $this->setting->get('cloudflare', []);
        $legacy = is_array($legacy) ? $legacy : [];

        $record = $this->sanitize_credentials($legacy);
        if ([] === $record) {
            return false;
        }

        $result['provider'][Edge::CLOUDFLARE] = $record;
        return true;
    }

    /**
     * Import legacy Cloudflare APO credentials into the destination.
     *
     * New-format data for the provider wins (same rule as Cloudflare Cache).
     *
     * @param array $result Migration result being built (by reference).
     * @return bool True if a provider record was written.
     */
    protected function import_apo(array &$result): bool
    {
        // New data wins.
        if (isset($result['provider'][Edge::CLOUDFLARE_APO])
            && is_array($result['provider'][Edge::CLOUDFLARE_APO])
            && [] !== $result['provider'][Edge::CLOUDFLARE_APO]) {
            return false;
        }

        $cdn = $this->setting->get('cdn', []);
        $cdn = is_array($cdn) ? $cdn : [];

        $legacy = isset($cdn['provider'][Edge::CLOUDFLARE_APO]) && is_array($cdn['provider'][Edge::CLOUDFLARE_APO])
            ? $cdn['provider'][Edge::CLOUDFLARE_APO]
            : [];

        $record = $this->sanitize_credentials($legacy);
        if ([] === $record) {
            return false;
        }

        // Preserve the APO-enabled flag when present.
        if (isset($legacy['apo_enabled'])) {
            $record['apo_enabled'] = (bool) $legacy['apo_enabled'];
        }

        $result['provider'][Edge::CLOUDFLARE_APO] = $record;
        return true;
    }

    /**
     * Build a sanitized provider credential record from a legacy array.
     *
     * Only returns non-empty records so that an all-empty legacy option is not
     * imported (which would otherwise mask the "no legacy data" no-op).
     *
     * @param array $legacy Legacy option payload.
     * @return array The sanitized record, or [] when nothing usable is present.
     */
    protected function sanitize_credentials(array $legacy): array
    {
        $record = [
            'api_token' => $this->normalize_api_token($legacy['api_token'] ?? ''),
            'zone_id' => sanitize_text_field( (string) ($legacy['zone_id'] ?? '')),
            'auth_type' => $this->normalize_auth_type($legacy['auth_type'] ?? 'token'),
            'account_email' => sanitize_email( (string) ($legacy['account_email'] ?? '')),
        ];

        $has_data = false;
        foreach ($record as $value) {
            if ('' !== $value) {
                $has_data = true;
                break;
            }
        }

        return $has_data ? $record : [];
    }

    /**
     * Normalize the auth type, defaulting to token.
     *
     * @param mixed $auth_type Raw auth type value.
     * @return string 'token'|'key'
     */
    protected function normalize_auth_type($auth_type): string
    {
        $auth_type = strtolower(sanitize_text_field( (string) $auth_type));

        return in_array($auth_type, ['token', 'key'], true) ? $auth_type : 'token';
    }

    /**
     * Normalize an API token: strip whitespace and a leading "Bearer " prefix.
     *
     * @param mixed $token Raw token value.
     * @return string
     */
    protected function normalize_api_token($token): string
    {
        $token = trim( (string) $token);

        if (stripos($token, 'bearer ') === 0) {
            $token = substr($token, 7);
        }

        $token = preg_replace('/\s+/', '', $token);

        return '' !== $token ? $token : '';
    }
}
