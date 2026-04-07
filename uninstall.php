<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('swdc_settings');
delete_option('swdc_last_run');
delete_option('swdc_logs');
wp_clear_scheduled_hook('swdc_run_scheduled_cleanup');
wp_clear_scheduled_hook('swdc_purge_old_logs');
