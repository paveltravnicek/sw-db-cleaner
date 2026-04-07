<?php
/**
 * Plugin Name: Čištění databáze
 * Description: Bezpečné čištění WordPress databáze s logy.
 * Version: 1.0
 * Author: Smart Websites
 * Author URI: https://smart-websites.cz
 * Text Domain: sw-db-cleaner
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SW_DB_Cleaner {
    const VERSION = '1.0';
    const OPTION_SETTINGS = 'swdc_settings';
    const OPTION_LAST_RUN = 'swdc_last_run';
    const OPTION_LOGS = 'swdc_logs';
    const CRON_HOOK = 'swdc_run_scheduled_cleanup';
    const CRON_HOOK_LOG_PURGE = 'swdc_purge_old_logs';
    const NONCE_ACTION_RUN = 'swdc_run_cleanup';
    const NONCE_ACTION_SAVE = 'swdc_save_settings';
    const MENU_SLUG = 'sw-database-cleaner';
    const MAX_LOGS = 100;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('cron_schedules', [$this, 'register_cron_schedules']);
        add_action(self::CRON_HOOK, [$this, 'run_scheduled_cleanup']);
        add_action(self::CRON_HOOK_LOG_PURGE, [$this, 'purge_old_logs']);
    }

    public static function activate() {
        $instance = self::instance();
        if (!get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, $instance->get_default_settings());
        }
        if (!get_option(self::OPTION_LOGS)) {
            add_option(self::OPTION_LOGS, []);
        }
        $instance->schedule_events();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::CRON_HOOK_LOG_PURGE);
    }

    public function register_cron_schedules($schedules) {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __('Jednou týdně', 'sw-db-cleaner'),
            ];
        }
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = [
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __('Jednou za 30 dní', 'sw-db-cleaner'),
            ];
        }
        return $schedules;
    }

    public function get_default_settings() {
        return [
            'auto_cleanup_enabled' => 1,
            'cleanup_frequency'    => 'weekly',
            'cleanup_revisions'    => 1,
            'cleanup_auto_drafts'  => 1,
            'cleanup_trashed_posts'=> 1,
            'cleanup_expired_transients' => 1,
            'cleanup_orphaned_transients'=> 1,
            'cleanup_spam_comments'=> 1,
            'cleanup_trashed_comments' => 1,
            'cleanup_orphaned_postmeta' => 0,
            'cleanup_orphaned_commentmeta' => 0,
            'optimize_tables' => 1,
            'auto_delete_logs_30_days' => 1,
        ];
    }

    public function get_settings() {
        return wp_parse_args(get_option(self::OPTION_SETTINGS, []), $this->get_default_settings());
    }

    public function register_admin_menu() {
        add_management_page(
            __('Čištění databáze', 'sw-db-cleaner'),
            __('Čištění databáze', 'sw-db-cleaner'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'tools_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style(
            'swdc-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'swdc-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            [],
            self::VERSION,
            true
        );

        wp_localize_script('swdc-admin', 'swdcAdmin', [
            'confirmDeleteLogs' => __('Opravdu chcete smazat všechny logy?', 'sw-db-cleaner'),
        ]);
    }

    public function handle_admin_actions() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (!isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG) {
            return;
        }

        if (isset($_POST['swdc_save_settings'])) {
            check_admin_referer(self::NONCE_ACTION_SAVE);
            $settings = $this->sanitize_settings($_POST);
            update_option(self::OPTION_SETTINGS, $settings, false);
            $this->schedule_events();
            wp_safe_redirect(add_query_arg('swdc_notice', 'settings-saved', menu_page_url(self::MENU_SLUG, false)));
            exit;
        }

        if (isset($_POST['swdc_run_cleanup'])) {
            check_admin_referer(self::NONCE_ACTION_RUN);
            $results = $this->perform_cleanup('manual');
            set_transient('swdc_admin_result', $results, 60);
            wp_safe_redirect(add_query_arg('swdc_notice', 'cleanup-run', menu_page_url(self::MENU_SLUG, false)));
            exit;
        }

        if (isset($_POST['swdc_delete_logs'])) {
            check_admin_referer('swdc_delete_logs');
            delete_option(self::OPTION_LOGS);
            add_option(self::OPTION_LOGS, []);
            wp_safe_redirect(add_query_arg('swdc_notice', 'logs-deleted', menu_page_url(self::MENU_SLUG, false)));
            exit;
        }
    }

    private function sanitize_settings($input) {
        $defaults = $this->get_default_settings();
        $settings = [];

        foreach ($defaults as $key => $default) {
            if (is_int($default)) {
                $settings[$key] = !empty($input[$key]) ? 1 : 0;
            } else {
                $settings[$key] = isset($input[$key]) ? sanitize_text_field(wp_unslash($input[$key])) : $default;
            }
        }

        $allowed_frequencies = ['daily', 'weekly', 'monthly'];
        if (!in_array($settings['cleanup_frequency'], $allowed_frequencies, true)) {
            $settings['cleanup_frequency'] = 'weekly';
        }

        return $settings;
    }

    private function schedule_events() {
        $settings = $this->get_settings();

        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::CRON_HOOK_LOG_PURGE);

        if (!wp_next_scheduled(self::CRON_HOOK_LOG_PURGE)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK_LOG_PURGE);
        }

        if (!empty($settings['auto_cleanup_enabled'])) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, $settings['cleanup_frequency'], self::CRON_HOOK);
        }
    }

    public function run_scheduled_cleanup() {
        $this->perform_cleanup('scheduled');
    }

    public function purge_old_logs() {
        $settings = $this->get_settings();
        if (empty($settings['auto_delete_logs_30_days'])) {
            return;
        }

        $logs = $this->get_logs();
        if (empty($logs)) {
            return;
        }

        $threshold = time() - (30 * DAY_IN_SECONDS);
        $filtered = array_values(array_filter($logs, static function($log) use ($threshold) {
            return !empty($log['timestamp']) && (int) $log['timestamp'] >= $threshold;
        }));

        update_option(self::OPTION_LOGS, $filtered, false);
    }

    private function get_wp_tables() {
        global $wpdb;
        $like = $wpdb->esc_like($wpdb->prefix) . '%';
        return $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
    }

    private function get_db_size_mb($tables = []) {
        global $wpdb;
        $size = 0;
        $status = $wpdb->get_results('SHOW TABLE STATUS');
        foreach ($status as $table) {
            if (!empty($tables) && !in_array($table->Name, $tables, true)) {
                continue;
            }
            $size += (float) $table->Data_length + (float) $table->Index_length;
        }
        return $size / 1024 / 1024;
    }

    private function perform_cleanup($trigger = 'manual') {
        global $wpdb;

        $settings = $this->get_settings();
        $tables = $this->get_wp_tables();
        $size_before = $this->get_db_size_mb($tables);
        $counts = [
            'revisions' => 0,
            'auto_drafts' => 0,
            'trashed_posts' => 0,
            'expired_transient_timeouts' => 0,
            'expired_transients' => 0,
            'expired_site_transient_timeouts' => 0,
            'expired_site_transients' => 0,
            'orphaned_transients' => 0,
            'orphaned_site_transients' => 0,
            'spam_comments' => 0,
            'trashed_comments' => 0,
            'orphaned_postmeta' => 0,
            'orphaned_commentmeta' => 0,
            'optimized_tables' => 0,
        ];

        if (!empty($settings['cleanup_revisions'])) {
            $counts['revisions'] = (int) $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'");
        }

        if (!empty($settings['cleanup_auto_drafts'])) {
            $counts['auto_drafts'] = (int) $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
        }

        if (!empty($settings['cleanup_trashed_posts'])) {
            $counts['trashed_posts'] = (int) $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'");
        }

        if (!empty($settings['cleanup_expired_transients'])) {
            $expired = $wpdb->get_col(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_timeout_%'
                 AND option_value < UNIX_TIMESTAMP()"
            );
            foreach ($expired as $timeout_name) {
                $transient_name = str_replace('_transient_timeout_', '_transient_', $timeout_name);
                $counts['expired_transient_timeouts'] += (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $timeout_name));
                $counts['expired_transients'] += (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", $transient_name));
            }

            if (is_multisite()) {
                $expired_site = $wpdb->get_col(
                    "SELECT meta_key FROM {$wpdb->sitemeta}
                     WHERE meta_key LIKE '_site_transient_timeout_%'
                     AND meta_value < UNIX_TIMESTAMP()"
                );
                foreach ($expired_site as $timeout_key) {
                    $transient_key = str_replace('_site_transient_timeout_', '_site_transient_', $timeout_key);
                    $counts['expired_site_transient_timeouts'] += (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->sitemeta} WHERE meta_key = %s", $timeout_key));
                    $counts['expired_site_transients'] += (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->sitemeta} WHERE meta_key = %s", $transient_key));
                }
            }
        }

        if (!empty($settings['cleanup_orphaned_transients'])) {
            $counts['orphaned_transients'] = (int) $wpdb->query(
                "DELETE o FROM {$wpdb->options} o
                 LEFT JOIN {$wpdb->options} t
                 ON t.option_name = REPLACE(o.option_name, '_transient_', '_transient_timeout_')
                 WHERE o.option_name LIKE '_transient_%'
                 AND o.option_name NOT LIKE '_transient_timeout_%'
                 AND t.option_id IS NULL"
            );

            if (is_multisite()) {
                $counts['orphaned_site_transients'] = (int) $wpdb->query(
                    "DELETE s FROM {$wpdb->sitemeta} s
                     LEFT JOIN {$wpdb->sitemeta} t
                     ON t.meta_key = REPLACE(s.meta_key, '_site_transient_', '_site_transient_timeout_')
                     WHERE s.meta_key LIKE '_site_transient_%'
                     AND s.meta_key NOT LIKE '_site_transient_timeout_%'
                     AND t.meta_id IS NULL"
                );
            }
        }

        if (!empty($settings['cleanup_spam_comments'])) {
            $counts['spam_comments'] = (int) $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
        }

        if (!empty($settings['cleanup_trashed_comments'])) {
            $counts['trashed_comments'] = (int) $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'");
        }

        if (!empty($settings['cleanup_orphaned_postmeta'])) {
            $counts['orphaned_postmeta'] = (int) $wpdb->query(
                "DELETE pm FROM {$wpdb->postmeta} pm
                 LEFT JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE p.ID IS NULL"
            );
        }

        if (!empty($settings['cleanup_orphaned_commentmeta'])) {
            $counts['orphaned_commentmeta'] = (int) $wpdb->query(
                "DELETE cm FROM {$wpdb->commentmeta} cm
                 LEFT JOIN {$wpdb->comments} c ON cm.comment_id = c.comment_ID
                 WHERE c.comment_ID IS NULL"
            );
        }

        if (!empty($settings['optimize_tables'])) {
            foreach ($tables as $table) {
                $result = $wpdb->query("OPTIMIZE TABLE `{$table}`");
                if ($result !== false) {
                    $counts['optimized_tables']++;
                }
            }
        }

        $size_after = $this->get_db_size_mb($tables);
        $saved_mb = max(0, $size_before - $size_after);

        $results = [
            'timestamp' => time(),
            'trigger' => $trigger,
            'size_before_mb' => round($size_before, 2),
            'size_after_mb' => round($size_after, 2),
            'saved_mb' => round($saved_mb, 2),
            'counts' => $counts,
        ];

        update_option(self::OPTION_LAST_RUN, $results, false);
        $this->add_log($results);

        return $results;
    }

    private function add_log($results) {
        $logs = $this->get_logs();
        array_unshift($logs, $results);
        $logs = array_slice($logs, 0, self::MAX_LOGS);
        update_option(self::OPTION_LOGS, $logs, false);
    }

    private function get_logs() {
        $logs = get_option(self::OPTION_LOGS, []);
        return is_array($logs) ? $logs : [];
    }

    private function get_last_run() {
        $last = get_option(self::OPTION_LAST_RUN, []);
        return is_array($last) ? $last : [];
    }

    private function get_frequency_label($frequency) {
        $map = [
            'daily' => 'Denně',
            'weekly' => 'Týdně',
            'monthly' => 'Jednou za 30 dní',
        ];
        return $map[$frequency] ?? $frequency;
    }

    private function format_datetime($timestamp) {
        if (empty($timestamp)) {
            return '—';
        }
        return wp_date('j. n. Y, G:i', (int) $timestamp);
    }


    private function trigger_label($trigger) {
        return $trigger === 'scheduled' ? 'Automaticky' : 'Ručně';
    }

    private function get_plugin_version() {
        if (!function_exists('get_file_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_file_data(__FILE__, ['Version' => 'Version']);
        return !empty($data['Version']) ? $data['Version'] : self::VERSION;
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Nemáte oprávnění.', 'sw-db-cleaner'));
        }

        $settings = $this->get_settings();
        $last_run = $this->get_last_run();
        $logs = $this->get_logs();
        $plugin_version = $this->get_plugin_version();
        $admin_result = get_transient('swdc_admin_result');
        if ($admin_result) {
            delete_transient('swdc_admin_result');
        }
        ?>
        <div class="wrap swdc-wrap">
            <section class="swdc-hero">
                <div class="swdc-hero-inner">
                    <div>
                        <span class="swdc-hero-brand">Smart Websites</span>
                        <h1>Čištění databáze</h1>
                        <p>Bezpečné čištění WordPress databáze s logy.</p>
                    </div>
                    <div class="swdc-version-card" aria-label="Verze pluginu">
                        <strong><?php echo esc_html($plugin_version); ?></strong>
                        <span>Verze pluginu</span>
                    </div>
                </div>
            </section>

            <div class="swdc-page-notices">
            <?php if (isset($_GET['swdc_notice'])) : ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    $notice = sanitize_text_field(wp_unslash($_GET['swdc_notice']));
                    if ($notice === 'settings-saved') {
                        echo esc_html__('Nastavení bylo uloženo.', 'sw-db-cleaner');
                    } elseif ($notice === 'cleanup-run') {
                        echo esc_html__('Čištění databáze bylo spuštěno.', 'sw-db-cleaner');
                    } elseif ($notice === 'logs-deleted') {
                        echo esc_html__('Logy byly smazány.', 'sw-db-cleaner');
                    }
                    ?>
                </p></div>
            <?php endif; ?>
            </div>

            <div class="swdc-grid">
                <section class="swdc-card swdc-card-highlight">
                    <h2>Doporučené výchozí nastavení</h2>
                    <p>Pro většinu běžných webů je bezpečné nechat zapnuté revize, auto-drafty, obsah v koši, transienty, spam komentáře a optimalizaci tabulek. Osiřelé <code>postmeta</code> a <code>commentmeta</code> doporučuji zapínat jen tehdy, pokud víš, že na webu nezlobí některý plugin nebo po něm zůstává nepořádek.</p>
                </section>

                <section class="swdc-card">
                    <h2>Poslední běh</h2>
                    <?php if (!empty($last_run)) : ?>
                        <div class="swdc-stats">
                            <div><strong><?php echo esc_html($this->format_datetime($last_run['timestamp'] ?? 0)); ?></strong><span>Datum a čas</span></div>
                            <div><strong><?php echo esc_html($this->trigger_label($last_run['trigger'] ?? 'manual')); ?></strong><span>Způsob spuštění</span></div>
                            <div><strong><?php echo esc_html(number_format_i18n((float) ($last_run['saved_mb'] ?? 0), 2)); ?> MB</strong><span>Uvolněno</span></div>
                        </div>
                    <?php else : ?>
                        <p>Zatím neproběhlo žádné čištění.</p>
                    <?php endif; ?>

                    <form method="post" class="swdc-run-form">
                        <?php wp_nonce_field(self::NONCE_ACTION_RUN); ?>
                        <button type="submit" name="swdc_run_cleanup" class="button button-primary button-hero">Spustit čištění databáze nyní</button>
                    </form>

                    <?php if (!empty($admin_result)) : ?>
                        <?php $counts = $admin_result['counts']; ?>
                        <details class="swdc-details swdc-accordion-item">
                            <summary>Souhrn posledního ručního čištění</summary>
                            <div class="swdc-details-content">
                                <ul class="swdc-result-list">
                                    <li>Revize: <strong><?php echo esc_html((string) $counts['revisions']); ?></strong></li>
                                    <li>Auto-drafty: <strong><?php echo esc_html((string) $counts['auto_drafts']); ?></strong></li>
                                    <li>Položky v koši: <strong><?php echo esc_html((string) $counts['trashed_posts']); ?></strong></li>
                                    <li>Expirované transient timeouty: <strong><?php echo esc_html((string) $counts['expired_transient_timeouts']); ?></strong></li>
                                    <li>Expirované transient hodnoty: <strong><?php echo esc_html((string) $counts['expired_transients']); ?></strong></li>
                                    <li>Osiřelé transienty: <strong><?php echo esc_html((string) $counts['orphaned_transients']); ?></strong></li>
                                    <li>Spam komentáře: <strong><?php echo esc_html((string) $counts['spam_comments']); ?></strong></li>
                                    <li>Komentáře v koši: <strong><?php echo esc_html((string) $counts['trashed_comments']); ?></strong></li>
                                    <li>Osiřelé postmeta: <strong><?php echo esc_html((string) $counts['orphaned_postmeta']); ?></strong></li>
                                    <li>Osiřelé commentmeta: <strong><?php echo esc_html((string) $counts['orphaned_commentmeta']); ?></strong></li>
                                    <li>Optimalizované tabulky: <strong><?php echo esc_html((string) $counts['optimized_tables']); ?></strong></li>
                                </ul>
                            </div>
                        </details>
                    <?php endif; ?>
                </section>
            </div>

            <div class="swdc-grid swdc-grid-main">
                <section class="swdc-card">
                    <h2>Nastavení čištění</h2>
                    <form method="post" class="swdc-settings-form">
                        <?php wp_nonce_field(self::NONCE_ACTION_SAVE); ?>

                        <div class="swdc-switches">
                            <label><input type="checkbox" name="auto_cleanup_enabled" value="1" <?php checked($settings['auto_cleanup_enabled'], 1); ?>> Automatické čištění databáze</label>
                            <label><input type="checkbox" name="auto_delete_logs_30_days" value="1" <?php checked($settings['auto_delete_logs_30_days'], 1); ?>> Automaticky mazat logy starší než 30 dní</label>
                        </div>

                        <div class="swdc-field">
                            <label for="cleanup_frequency"><strong>Frekvence automatického čištění</strong></label>
                            <select name="cleanup_frequency" id="cleanup_frequency">
                                <option value="daily" <?php selected($settings['cleanup_frequency'], 'daily'); ?>>Denně</option>
                                <option value="weekly" <?php selected($settings['cleanup_frequency'], 'weekly'); ?>>Týdně</option>
                                <option value="monthly" <?php selected($settings['cleanup_frequency'], 'monthly'); ?>>Jednou za 30 dní</option>
                            </select>
                            <p class="description">Aktuálně: <?php echo esc_html($this->get_frequency_label($settings['cleanup_frequency'])); ?></p>
                        </div>

                        <div class="swdc-options-grid">
                            <label><input type="checkbox" name="cleanup_revisions" value="1" <?php checked($settings['cleanup_revisions'], 1); ?>> Mazat revize</label>
                            <label><input type="checkbox" name="cleanup_auto_drafts" value="1" <?php checked($settings['cleanup_auto_drafts'], 1); ?>> Mazat auto-drafty</label>
                            <label><input type="checkbox" name="cleanup_trashed_posts" value="1" <?php checked($settings['cleanup_trashed_posts'], 1); ?>> Mazat obsah v koši</label>
                            <label><input type="checkbox" name="cleanup_expired_transients" value="1" <?php checked($settings['cleanup_expired_transients'], 1); ?>> Mazat expirované transienty</label>
                            <label><input type="checkbox" name="cleanup_orphaned_transients" value="1" <?php checked($settings['cleanup_orphaned_transients'], 1); ?>> Mazat osiřelé transienty</label>
                            <label><input type="checkbox" name="cleanup_spam_comments" value="1" <?php checked($settings['cleanup_spam_comments'], 1); ?>> Mazat spam komentáře</label>
                            <label><input type="checkbox" name="cleanup_trashed_comments" value="1" <?php checked($settings['cleanup_trashed_comments'], 1); ?>> Mazat komentáře v koši</label>
                            <label><input type="checkbox" name="cleanup_orphaned_postmeta" value="1" <?php checked($settings['cleanup_orphaned_postmeta'], 1); ?>> Mazat osiřelé postmeta</label>
                            <label><input type="checkbox" name="cleanup_orphaned_commentmeta" value="1" <?php checked($settings['cleanup_orphaned_commentmeta'], 1); ?>> Mazat osiřelé commentmeta</label>
                            <label><input type="checkbox" name="optimize_tables" value="1" <?php checked($settings['optimize_tables'], 1); ?>> Optimalizovat WP tabulky</label>
                        </div>

                        <p><button type="submit" name="swdc_save_settings" class="button button-primary">Uložit nastavení</button></p>
                    </form>
                </section>

                <section class="swdc-card">
                    <div class="swdc-card-header-inline">
                        <h2>Logy čištění</h2>
                        <form method="post" onsubmit="return window.confirm(swdcAdmin.confirmDeleteLogs);">
                            <?php wp_nonce_field('swdc_delete_logs'); ?>
                            <button type="submit" name="swdc_delete_logs" class="button button-secondary">Smazat všechny logy</button>
                        </form>
                    </div>

                    <?php if (!empty($logs)) : ?>
                        <div class="swdc-log-list swdc-accordion" data-accordion="true">
                            <?php foreach ($logs as $index => $log) : ?>
                                <details class="swdc-details swdc-accordion-item">
                                    <summary>
                                        <span><?php echo esc_html($this->format_datetime($log['timestamp'] ?? 0)); ?></span>
                                        <span class="swdc-log-meta"><?php echo esc_html($this->trigger_label($log['trigger'] ?? 'manual')); ?> · Uvolněno <?php echo esc_html(number_format_i18n((float) ($log['saved_mb'] ?? 0), 2)); ?> MB</span>
                                    </summary>
                                    <div class="swdc-details-content">
                                        <ul class="swdc-result-list">
                                            <li>Velikost před: <strong><?php echo esc_html(number_format_i18n((float) ($log['size_before_mb'] ?? 0), 2)); ?> MB</strong></li>
                                            <li>Velikost po: <strong><?php echo esc_html(number_format_i18n((float) ($log['size_after_mb'] ?? 0), 2)); ?> MB</strong></li>
                                            <li>Revize: <strong><?php echo esc_html((string) ($log['counts']['revisions'] ?? 0)); ?></strong></li>
                                            <li>Auto-drafty: <strong><?php echo esc_html((string) ($log['counts']['auto_drafts'] ?? 0)); ?></strong></li>
                                            <li>Položky v koši: <strong><?php echo esc_html((string) ($log['counts']['trashed_posts'] ?? 0)); ?></strong></li>
                                            <li>Expirované timeouty transientů: <strong><?php echo esc_html((string) ($log['counts']['expired_transient_timeouts'] ?? 0)); ?></strong></li>
                                            <li>Expirované transienty: <strong><?php echo esc_html((string) ($log['counts']['expired_transients'] ?? 0)); ?></strong></li>
                                            <li>Osiřelé transienty: <strong><?php echo esc_html((string) ($log['counts']['orphaned_transients'] ?? 0)); ?></strong></li>
                                            <li>Spam komentáře: <strong><?php echo esc_html((string) ($log['counts']['spam_comments'] ?? 0)); ?></strong></li>
                                            <li>Komentáře v koši: <strong><?php echo esc_html((string) ($log['counts']['trashed_comments'] ?? 0)); ?></strong></li>
                                            <li>Osiřelé postmeta: <strong><?php echo esc_html((string) ($log['counts']['orphaned_postmeta'] ?? 0)); ?></strong></li>
                                            <li>Osiřelé commentmeta: <strong><?php echo esc_html((string) ($log['counts']['orphaned_commentmeta'] ?? 0)); ?></strong></li>
                                            <li>Optimalizované tabulky: <strong><?php echo esc_html((string) ($log['counts']['optimized_tables'] ?? 0)); ?></strong></li>
                                        </ul>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <p>Zatím nejsou k dispozici žádné logy.</p>
                    <?php endif; ?>
                </section>
            </div>
        </div>
        <?php
    }
}

SW_DB_Cleaner::instance();
register_activation_hook(__FILE__, ['SW_DB_Cleaner', 'activate']);
register_deactivation_hook(__FILE__, ['SW_DB_Cleaner', 'deactivate']);
