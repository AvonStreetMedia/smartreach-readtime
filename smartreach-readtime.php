<?php
/**
 * Plugin Name: SmartReach Read Time
 * Description: Displays an estimated "X min read" for posts. Includes settings, shortcode, template tag, and optional automatic output.
 * Version: 1.0.2
 * Author: Chad Latta / Avon Street Media
 * License: GPLv2 or later
 * Text Domain: smartreach-readtime
 */

if (!defined('ABSPATH')) exit;

define('SR_READTIME_VERSION', '1.0.2');
define('SR_READTIME_OPT', 'sr_readtime_options');

function sr_readtime_default_options() {
  return [
    'enabled' => 1,
    'wpm'     => 220,
    'prefix'  => 'min read',
    // Inline span by default so it sits naturally in post meta
    'html'    => '<span class="sr-readtime"><span class="sr-readtime__value">%d</span> %s</span>',
  ];
}

register_activation_hook(__FILE__, function () {
  $opts = get_option(SR_READTIME_OPT);
  if (!is_array($opts)) {
    add_option(SR_READTIME_OPT, sr_readtime_default_options(), '', false); // autoload=no
  } else {
    update_option(SR_READTIME_OPT, array_merge(sr_readtime_default_options(), $opts), false);
  }
});

function sr_readtime_compute($post_id = null) {
  $post = $post_id ? get_post($post_id) : get_post();
  if (!$post) return 1;
  $content = wp_strip_all_tags($post->post_content);
  // Normalize punctuation to spaces, then count words
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

  // Ensure required placeholders exist
  if (strpos($markup, '%d') === false || strpos($markup, '%s') === false) {
    $markup = sr_readtime_default_options()['html'];
  }

  $html = sprintf($markup, $mins, esc_html($label));
  return wp_kses_post($html);
}

// Optional: auto-prepend on single posts
add_filter('the_content', function ($content) {
  if (is_admin() || !is_singular('post')) return $content;
  $opts = array_merge(sr_readtime_default_options(), (array)get_option(SR_READTIME_OPT, []));
  if (!empty($opts['enabled'])) {
    sr_readtime_enqueue_styles();
    return sr_readtime_render() . $content;
  }
  return $content;
}, 5);

// Shortcodes
function sr_readtime_shortcode() {
  sr_readtime_enqueue_styles();
  return sr_readtime_render();
}
add_shortcode('read_time', 'sr_readtime_shortcode');
add_shortcode('smartreach_read_time', 'sr_readtime_shortcode');

// Template tag
function sr_read_time($post_id = null) {
  echo sr_readtime_render($post_id);
}

// CSS: fully inherit meta styles
function sr_readtime_enqueue_styles() {
  static $enqueued = false;
  if ($enqueued) return;
  $enqueued = true;

  $css = '.sr-readtime{display:inline;font:inherit;color:inherit;line-height:inherit;letter-spacing:inherit;text-transform:inherit;margin:0;padding:0}
          .sr-readtime__value{font-weight:inherit}';

  wp_register_style('sr-readtime-inline', false, [], SR_READTIME_VERSION);
  wp_enqueue_style('sr-readtime-inline');
  wp_add_inline_style('sr-readtime-inline', $css);
}

// Settings page
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
    if (strpos($html, '%d') === false || strp
