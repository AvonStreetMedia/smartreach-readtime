<?php
/**
 * Plugin Name: SmartReach Read Time
 * Plugin URI: https://avonstreetmedia.com/smartreach-readtime
 * Description: Displays an estimated "X min read" for posts. Includes settings, shortcode, template tag, and optional automatic output.
 * Version: 1.0.3
 * Author: Chad Latta / Avon Street Media
 * Author URI: https://avonstreetmedia.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: smartreach-readtime
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Plugin constants
define('SR_READTIME_VERSION', '1.0.3');
define('SR_READTIME_OPT', 'sr_readtime_options');
define('SR_READTIME_META_KEY', '_sr_readtime_minutes');
define('SR_READTIME_PLUGIN_FILE', __FILE__);
define('SR_READTIME_PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * Get default plugin options
 *
 * @return array Default options
 */
function sr_readtime_default_options() {
    return [
        'enabled'       => 1,
        'wpm'           => 220,
        'prefix'        => 'min read',
        'cache_enabled' => 1,
        'post_types'    => ['post'],
        'html'          => '<span class="sr-readtime"><span class="sr-readtime__value">%d</span> %s</span>',
    ];
}

/**
 * Plugin activation hook
 */
function sr_readtime_activate() {
    $opts = get_option(SR_READTIME_OPT);
    if (!is_array($opts)) {
        add_option(SR_READTIME_OPT, sr_readtime_default_options(), '', false);
    } else {
        update_option(SR_READTIME_OPT, array_merge(sr_readtime_default_options(), $opts), false);
    }
    
    // Clear any existing cache on activation
    sr_readtime_clear_all_cache();
}
register_activation_hook(__FILE__, 'sr_readtime_activate');

/**
 * Compute reading time for a post
 *
 * @param int|null $post_id Post ID (null for current post)
 * @return int Reading time in minutes (minimum 1)
 */
function sr_readtime_compute($post_id = null) {
    $post = $post_id ? get_post($post_id) : get_post();
    if (!$post) {
        return 1;
    }
    
    $opts = get_option(SR_READTIME_OPT, sr_readtime_default_options());
    $opts = array_merge(sr_readtime_default_options(), (array)$opts);
    
    // Check cache first if enabled
    if (!empty($opts['cache_enabled'])) {
        $cached = get_post_meta($post->ID, SR_READTIME_META_KEY, true);
        if ($cached && is_numeric($cached)) {
            return max(1, (int)$cached);
        }
    }
    
    // Strip all HTML and shortcodes
    $content = strip_shortcodes($post->post_content);
    $content = wp_strip_all_tags($content);
    
    // Remove extra whitespace and normalize
    $content = preg_replace('/\s+/', ' ', $content);
    
    // Count words (handles UTF-8 properly)
    $words = str_word_count($content);
    
    // Calculate reading time
    $wpm = max(100, (int)$opts['wpm']);
    $mins = (int)ceil($words / $wpm);
    $mins = max(1, $mins);
    
    // Cache the result if enabled
    if (!empty($opts['cache_enabled'])) {
        update_post_meta($post->ID, SR_READTIME_META_KEY, $mins);
    }
    
    return $mins;
}

/**
 * Render the read time HTML
 *
 * @param int|null $post_id Post ID (null for current post)
 * @return string HTML output
 */
function sr_readtime_render($post_id = null) {
    $opts = get_option(SR_READTIME_OPT, sr_readtime_default_options());
    $opts = array_merge(sr_readtime_default_options(), (array)$opts);
    
    $mins = sr_readtime_compute($post_id);
    $label = sanitize_text_field($opts['prefix']);
    $markup = $opts['html'];
    
    // Validate markup has required placeholders
    if (strpos($markup, '%d') === false || strpos($markup, '%s') === false) {
        $markup = sr_readtime_default_options()['html'];
    }
    
    // Build the HTML
    $html = sprintf($markup, $mins, esc_html($label));
    
    /**
     * Filter the read time HTML output
     *
     * @param string $html The HTML output
     * @param int $mins Reading time in minutes
     * @param int|null $post_id Post ID
     */
    return apply_filters('sr_readtime_html', wp_kses_post($html), $mins, $post_id);
}

/**
 * Auto-prepend read time to content
 *
 * @param string $content Post content
 * @return string Modified content
 */
function sr_readtime_auto_display($content) {
    // Don't run in admin, feeds, or REST API
    if (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return $content;
    }
    
    $opts = get_option(SR_READTIME_OPT, sr_readtime_default_options());
    $opts = array_merge(sr_readtime_default_options(), (array)$opts);
    
    // Check if enabled
    if (empty($opts['enabled'])) {
        return $content;
    }
    
    // Check if we're on a supported post type
    $post_types = !empty($opts['post_types']) ? (array)$opts['post_types'] : ['post'];
    if (!is_singular($post_types)) {
        return $content;
    }
    
    // Enqueue styles and prepend read time
    sr_readtime_enqueue_styles();
    return sr_readtime_render() . $content;
}
add_filter('the_content', 'sr_readtime_auto_display', 5);

/**
 * Shortcode handler
 *
 * @param array $atts Shortcode attributes
 * @return string HTML output
 */
function sr_readtime_shortcode($atts = []) {
    $atts = shortcode_atts([
        'post_id' => null,
    ], $atts, 'read_time');
    
    sr_readtime_enqueue_styles();
    return sr_readtime_render($atts['post_id']);
}
add_shortcode('read_time', 'sr_readtime_shortcode');
add_shortcode('smartreach_read_time', 'sr_readtime_shortcode');

/**
 * Template tag for theme developers
 *
 * @param int|null $post_id Post ID (null for current post)
 * @param bool $echo Whether to echo or return
 * @return string|void HTML output if $echo is false
 */
function sr_read_time($post_id = null, $echo = true) {
    $output = sr_readtime_render($post_id);
    
    if ($echo) {
        echo $output;
    } else {
        return $output;
    }
}

/**
 * Enqueue inline styles
 */
function sr_readtime_enqueue_styles() {
    static $enqueued = false;
    if ($enqueued) {
        return;
    }
    $enqueued = true;
    
    $css = '.sr-readtime{display:inline!important;font:inherit!important;color:inherit!important;line-height:inherit!important;letter-spacing:inherit!important;text-transform:inherit!important;margin:0!important;padding:0!important;border:0!important;background:none!important}
.sr-readtime__value{font:inherit!important;color:inherit!important}';
    
    /**
     * Filter the read time CSS
     *
     * @param string $css The CSS code
     */
    $css = apply_filters('sr_readtime_css', $css);
    
    wp_register_style('sr-readtime-inline', false, [], SR_READTIME_VERSION);
    wp_enqueue_style('sr-readtime-inline');
    wp_add_inline_style('sr-readtime-inline', $css);
}

/**
 * Clear cache for a specific post
 *
 * @param int $post_id Post ID
 */
function sr_readtime_clear_cache($post_id) {
    delete_post_meta($post_id, SR_READTIME_META_KEY);
}

/**
 * Clear cache for all posts
 */
function sr_readtime_clear_all_cache() {
    global $wpdb;
    $wpdb->delete(
        $wpdb->postmeta,
        ['meta_key' => SR_READTIME_META_KEY],
        ['%s']
    );
}

/**
 * Clear cache when post is saved
 *
 * @param int $post_id Post ID
 */
function sr_readtime_clear_cache_on_save($post_id) {
    // Skip autosaves and revisions
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    
    sr_readtime_clear_cache($post_id);
}
add_action('save_post', 'sr_readtime_clear_cache_on_save');

/**
 * Add settings page to admin menu
 */
function sr_readtime_admin_menu() {
    add_options_page(
        __('Read Time Settings', 'smartreach-readtime'),
        __('Read Time', 'smartreach-readtime'),
        'manage_options',
        'smartreach-readtime',
        'sr_readtime_settings_page'
    );
}
add_action('admin_menu', 'sr_readtime_admin_menu');

/**
 * Settings page HTML
 */
function sr_readtime_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'smartreach-readtime'));
    }
    
    // Handle form submission
    if (isset($_POST['sr_rt_nonce']) && wp_verify_nonce($_POST['sr_rt_nonce'], 'sr_rt_save')) {
        $opts = array_merge(sr_readtime_default_options(), (array)get_option(SR_READTIME_OPT, []));
        
        $opts['enabled'] = isset($_POST['enabled']) ? 1 : 0;
        $opts['cache_enabled'] = isset($_POST['cache_enabled']) ? 1 : 0;
        $opts['wpm'] = max(100, (int)($_POST['wpm'] ?? 220));
        $opts['prefix'] = sanitize_text_field($_POST['prefix'] ?? 'min read');
        
        // Handle post types
        $opts['post_types'] = isset($_POST['post_types']) && is_array($_POST['post_types']) 
            ? array_map('sanitize_text_field', $_POST['post_types']) 
            : ['post'];
        
        // Validate HTML template
        $html = wp_unslash($_POST['html'] ?? sr_readtime_default_options()['html']);
        if (strpos($html, '%d') === false || strpos($html, '%s') === false) {
            $html = sr_readtime_default_options()['html'];
        }
        $opts['html'] = $html;
        
        update_option(SR_READTIME_OPT, $opts, false);
        
        // Clear cache if cache was disabled
        if (empty($opts['cache_enabled'])) {
            sr_readtime_clear_all_cache();
        }
        
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved successfully.', 'smartreach-readtime') . '</p></div>';
    }
    
    // Handle cache clear
    if (isset($_POST['sr_rt_clear_cache_nonce']) && wp_verify_nonce($_POST['sr_rt_clear_cache_nonce'], 'sr_rt_clear_cache')) {
        sr_readtime_clear_all_cache();
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Cache cleared successfully.', 'smartreach-readtime') . '</p></div>';
    }
    
    $opts = array_merge(sr_readtime_default_options(), (array)get_option(SR_READTIME_OPT, []));
    $post_types = get_post_types(['public' => true], 'objects');
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <form method="post" action="">
            <?php wp_nonce_field('sr_rt_save', 'sr_rt_nonce'); ?>
            
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="enabled"><?php esc_html_e('Enable Auto Display', 'smartreach-readtime'); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="enabled" id="enabled" value="1" <?php checked($opts['enabled'], 1); ?>>
                                    <?php esc_html_e('Automatically display read time on posts', 'smartreach-readtime'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When enabled, read time will be prepended to the post content automatically.', 'smartreach-readtime'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="post_types"><?php esc_html_e('Post Types', 'smartreach-readtime'); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <?php foreach ($post_types as $post_type): ?>
                                    <label style="display: block; margin-bottom: 5px;">
                                        <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($post_type->name); ?>" 
                                            <?php checked(in_array($post_type->name, $opts['post_types'])); ?>>
                                        <?php echo esc_html($post_type->label); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description">
                                    <?php esc_html_e('Select which post types should display read time.', 'smartreach-readtime'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="wpm"><?php esc_html_e('Words Per Minute', 'smartreach-readtime'); ?></label>
                        </th>
                        <td>
                            <input type="number" name="wpm" id="wpm" value="<?php echo esc_attr($opts['wpm']); ?>" 
                                min="100" max="500" step="10" class="small-text" required>
                            <p class="description">
                                <?php esc_html_e('Average reading speed in words per minute. Default is 220 (adult average).', 'smartreach-readtime'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="prefix"><?php esc_html_e('Label Text', 'smartreach-readtime'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="prefix" id="prefix" value="<?php echo esc_attr($opts['prefix']); ?>" 
                                class="regular-text" required>
                            <p class="description">
                                <?php esc_html_e('Text displayed after the number (e.g., "min read", "minute read").', 'smartreach-readtime'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="cache_enabled"><?php esc_html_e('Enable Caching', 'smartreach-readtime'); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="cache_enabled" id="cache_enabled" value="1" <?php checked($opts['cache_enabled'], 1); ?>>
                                    <?php esc_html_e('Cache reading time calculations', 'smartreach-readtime'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('Recommended. Caches are automatically cleared when posts are updated.', 'smartreach-readtime'); ?>
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="html"><?php esc_html_e('HTML Template', 'smartreach-readtime'); ?></label>
                        </th>
                        <td>
                            <textarea name="html" id="html" rows="3" class="large-text code" required><?php echo esc_textarea($opts['html']); ?></textarea>
                            <p class="description">
                                <?php esc_html_e('HTML template for output. Use %d for minutes and %s for label text. Both placeholders are required.', 'smartreach-readtime'); ?>
                            </p>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <?php submit_button(__('Save Settings', 'smartreach-readtime')); ?>
        </form>
        
        <hr>
        
        <h2><?php esc_html_e('Cache Management', 'smartreach-readtime'); ?></h2>
        <form method="post" action="">
            <?php wp_nonce_field('sr_rt_clear_cache', 'sr_rt_clear_cache_nonce'); ?>
            <p><?php esc_html_e('Clear all cached reading times. Useful if you\'ve changed the words per minute setting.', 'smartreach-readtime'); ?></p>
            <?php submit_button(__('Clear All Cache', 'smartreach-readtime'), 'secondary', 'submit', false); ?>
        </form>
        
        <hr>
        
        <h2><?php esc_html_e('Usage Instructions', 'smartreach-readtime'); ?></h2>
        <h3><?php esc_html_e('Shortcode', 'smartreach-readtime'); ?></h3>
        <p><?php esc_html_e('Add read time anywhere in your content:', 'smartreach-readtime'); ?></p>
        <pre><code>[read_time]</code></pre>
        <p><?php esc_html_e('Or use the alternative shortcode:', 'smartreach-readtime'); ?></p>
        <pre><code>[smartreach_read_time]</code></pre>
        <p><?php esc_html_e('Display read time for a specific post:', 'smartreach-readtime'); ?></p>
        <pre><code>[read_time post_id="123"]</code></pre>
        
        <h3><?php esc_html_e('Template Tag', 'smartreach-readtime'); ?></h3>
        <p><?php esc_html_e('Use in your theme templates:', 'smartreach-readtime'); ?></p>
        <pre><code>&lt;?php sr_read_time(); ?&gt;</code></pre>
        <p><?php esc_html_e('Display read time for a specific post:', 'smartreach-readtime'); ?></p>
        <pre><code>&lt;?php sr_read_time(123); ?&gt;</code></pre>
        <p><?php esc_html_e('Return the value instead of echoing:', 'smartreach-readtime'); ?></p>
        <pre><code>&lt;?php $read_time = sr_read_time(null, false); ?&gt;</code></pre>
        
        <h3><?php esc_html_e('Filters for Developers', 'smartreach-readtime'); ?></h3>
        <p><?php esc_html_e('Modify the HTML output:', 'smartreach-readtime'); ?></p>
        <pre><code>add_filter('sr_readtime_html', function($html, $mins, $post_id) {
    return $html;
}, 10, 3);</code></pre>
        <p><?php esc_html_e('Modify the CSS:', 'smartreach-readtime'); ?></p>
        <pre><code>add_filter('sr_readtime_css', function($css) {
    return $css;
});</code></pre>
    </div>
    <?php
}

/**
 * Add settings link to plugins page
 *
 * @param array $links Existing links
 * @return array Modified links
 */
function sr_readtime_plugin_action_links($links) {
    $settings_link = sprintf(
        '<a href="%s">%s</a>',
        admin_url('options-general.php?page=smartreach-readtime'),
        __('Settings', 'smartreach-readtime')
    );
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'sr_readtime_plugin_action_links');

/**
 * Load plugin text domain for translations
 */
function sr_readtime_load_textdomain() {
    load_plugin_textdomain(
        'smartreach-readtime',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
add_action('plugins_loaded', 'sr_readtime_load_textdomain');
