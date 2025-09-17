<?php
/*
Plugin Name: IoT Firmware Manager
Plugin URI: https://github.com/topobash/iot-firmware-manager
Description: Plugin untuk mengelola firmware OTA ESP8266/ESP32.
Version: 1.0.0
Author: cobaterus
Author URI: https://cobaterus.com
*/

if (!defined('ABSPATH')) exit;

// Hook untuk cek update
add_filter('pre_set_site_transient_update_plugins', function($transient) {
    if (empty($transient->checked)) return $transient;

    $plugin_slug = plugin_basename(__FILE__);
    $remote = wp_remote_get('https://raw.githubusercontent.com/topobash/iot-firmware-manager/main/update.json');

    if (!is_wp_error($remote) && wp_remote_retrieve_response_code($remote) == 200) {
        $data = json_decode(wp_remote_retrieve_body($remote));
        $current_version = '1.0.0';

        if ($data && version_compare($current_version, $data->version, '<')) {
            $transient->response[$plugin_slug] = (object) [
                'slug'        => $plugin_slug,
                'plugin'      => $plugin_slug,
                'new_version' => $data->version,
                'url'         => 'https://github.com/topobash/iot-firmware-manager',
                'package'     => $data->download_url
            ];
        }
    }
    return $transient;
});
