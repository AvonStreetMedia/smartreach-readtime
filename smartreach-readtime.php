<?php
/**
 * Plugin Name: SmartReach Read Time
 * Description: Displays an estimated "X min read" for posts. Includes settings, shortcode, and optional automatic output.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPLv2 or later
 * Text Domain: smartreach-readtime
 */

if (!defined('ABSPATH')) exit;

define('SR_READTIME_VERSION', '1.0.0');
define('SR_READTIME_OPT', 'sr_readtime_options');

function sr_readtime_default_options() {
  return [
    'enabled' => 1,
    'wpm'     => 220,
    'prefix'  => 'min read',
    'html'    => '<p class="sr-readtime"><span class="sr-readtime__value">%d</span> %s</p>',
  ];
}

register_activation_hook(__FILE__, function () {
  $opts = get_option(SR_READTIME_OPT);
  if (!is_array($opts)) {
    add_option(SR_READTIME_OPT, sr_readtime_default_options(), '', false);
  } else {
    update_option(SR_READTIME_OPT, array_merge(sr_readtime_default_options(), $opts), false);
  }
});

function sr_readtime_compute($post_id = null) {
  $post = $post_id ? get_post($post_id) : get_post();
  if (!$post) return 1;
  $content = wp_strip_all_tags($post->post_content);
  $words = str_word_count( preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $content) );
  $opts  = array_merge(sr_readtime_default_options(), (array)get_option(SR_READTIME_OPT, []));
  $wpm   = max(100, (int)$opts['wpm']);
  $mins  = (int)ceil($words / $wpm);
  return max(1, $mins);
}

function sr_readtime_render($post_id = null) {
  $opts   = array_merge(sr_readtime_default_options(), (array)get_option(SR_READTIME_OPT, []));
  $mins   = sr_readtime_compute($post_id);
  $label  = sanitize_text_field($opts['prefix']);
  $markup = $opts['html'];

  if (strpos($markup, '%d') === false || strpos($markup, '%s') === false) {
    $markup = sr_readtime_default_options()['html'];
  }

  $html = sprintf($markup, $mins, esc_html($label));
  return wp_kses_post($html);
}

add_filter('the_content', function ($content) {
  if (is_admin() || !is_singular('post')) return $content;
  $opts = array_merge(sr_readtime_default_options(), (array)get_option(SR_READTIME_OPT, []));
  if (!empty($opts['enabled'])) {
    sr_readtime_enqueue_styles();
    return sr_readtime_render() . $content;
  }
  return $content;
}, 5);

function sr_readtime_shortcode() {
  sr_readtime_enqueue_styles();
  return sr_readtime_render();
}
add_shortcode('read_time', 'sr_readtime_shortcode');
add_shortcode('smartreach_read_time', 'sr_readtime_shortcode');

function sr_read_time($post_id = null) {
  echo sr_readtime_render($post_id);
}

function sr_readtime_enqueue_styles() {
  static $enqueued = false;
  if ($enqueued) return;
  $enqueued = true;

  $css = '.sr-readtime{margin:0 0 0.75rem;color:#555;font-size:.95rem}
          .sr-readtime__value{font-weight:600}';

  wp_register_style('sr-readtime-inline', false, [], SR_READTIME_VERSION);
  wp_enqueue_style('sr-readtime-inline');
  wp_add_inline_style('sr-readtime-inline', $css);
}

add_action('admin_menu', function () {
  add_options_page(
    __('Read Time', 'smartreach-readtime'),
    __('Read Time', 'smartreach-readtime'),
    'manage_options',
    'smartreach-readtime',
    'sr_readtime_settings_page'
  );
});

function sr_readtime_settings_page() {
  if (!current_user_can('manage_options')) return;
  if (isset($_POST['sr_rt_nonce']) && wp_verify_nonce($_POST['sr_rt_nonce'], 'sr_rt_save')) {
    $opts = array_merge(sr_readtime_default_options(), (array)get_option(SR_READTIME_OPT, []));
    $opts['enabled'] = isset($_POST['enabled']) ? 1 : 0;
    $opts['wpm']     = max(100, (int)($_POST['wpm'] ?? 220));
    $opts['prefix']  = sanitize_text_field($_POST['prefix'] ?? 'min read');
    $html = wp_unslash($_POST['html'] ?? sr_readtime_default_options()['html']);
    if (strpos($html, '%d') === false || strpos($html, '%s') === false) {
      $html = sr_readtime_default_options()['html'];
    }
    $html = preg_replace('#</?(script|style)[^>]*>#i', '', $html);
    $opts['html'] = $html;
    update_option(SR_READTIME_OPT, $opts, false);
    echo '<div class="updated"><p>' . esc_html__('Settings saved.', 'smartreach-readtime') . '</p></div>';
  }
  $opts = array_merge(sr_readtime_default_options(), (array)get_option(SR_READTIME_OPT, []));
  ?>
  <div class="wrap">
    <h1><?php esc_html_e('SmartReach Read Time', 'smartreach-readtime'); ?></h1>
    <form method="post" action="">
      <?php wp_nonce_field('sr_rt_save', 'sr_rt_nonce'); ?>
      <table class="form-table" role="presentation">
        <tr><th scope="row"><?php esc_html_e('Auto display on posts', 'smartreach-readtime'); ?></th>
          <td><label><input type="checkbox" name="enabled" value="1" <?php checked($opts['enabled']); ?>><?php esc_html_e('Prepend read time to post content automatically.', 'smartreach-readtime'); ?></label></td>
        </tr>
        <tr><th scope="row"><?php esc_html_e('Words per minute (WPM)', 'smartreach-readtime'); ?></th>
          <td><input name="wpm" type="number" min="100" step="10" value="<?php echo esc_attr($opts['wpm']); ?>" class="small-text"></td>
        </tr>
        <tr><th scope="row"><?php esc_html_e('Label text', 'smartreach-readtime'); ?></th>
          <td><input name="prefix" type="text" value="<?php echo esc_attr($opts['prefix']); ?>" class="regular-text"></td>
        </tr>
        <tr><th scope="row"><?php esc_html_e('HTML template', 'smartreach-readtime'); ?></th>
          <td><textarea name="html" rows="4" class="large-text code"><?php echo esc_textarea($opts['html']); ?></textarea></td>
        </tr>
      </table>
      <?php submit_button(); ?>
    </form>
  </div>
  <?php
}

add_action('plugins_loaded', function () {
  load_plugin_textdomain('smartreach-readtime', false, dirname(plugin_basename(__FILE__)) . '/languages');
});
