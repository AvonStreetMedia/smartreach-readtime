<?php
/**
 * Uninstall SmartReach Read Time
 *
 * Fired when the plugin is uninstalled.
 *
 * @package SmartReach_Read_Time
 * @since 1.0.0
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('sr_readtime_options');

// For multisite installations
if (is_multisite()) {
    global $wpdb;
    $blog_ids = $wpdb->get_col("SELECT blog_id FROM $wpdb->blogs");
    
    foreach ($blog_ids as $blog_id) {
        switch_to_blog($blog_id);
        
        // Delete options for this site
        delete_option('sr_readtime_options');
        
        // Delete all cached read time post meta
        $wpdb->delete(
            $wpdb->postmeta,
            ['meta_key' => '_sr_readtime_minutes'],
            ['%s']
        );
        
        restore_current_blog();
    }
} else {
    // Delete all cached read time post meta
    global $wpdb;
    $wpdb->delete(
        $wpdb->postmeta,
        ['meta_key' => '_sr_readtime_minutes'],
        ['%s']
    );
}

// Clear any scheduled cron jobs (if we add any in the future)
wp_clear_scheduled_hook('sr_readtime_cleanup');
