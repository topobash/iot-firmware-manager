<?php
/*
Plugin Name: IoT Firmware Manager
Description: Plugin untuk mengelola firmware IoT device dan menyediakan endpoint OTA update.
Version: 1.0.0
Author: Cobaterus
*/

if (!defined('ABSPATH')) {
    exit;
}

// === Buat menu admin IoT Firmware ===
add_action('admin_menu', function () {
    add_menu_page(
        'IoT Firmware',
        'IoT Firmware',
        'manage_options',
        'iot-firmware',
        'iot_firmware_admin_page',
        'dashicons-hammer',
        80
    );
});

function iot_firmware_admin_page()
{
    ?>
    <div class="wrap">
        <h1>IoT Firmware Manager</h1>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="firmware_file" accept=".bin" required>
            <input type="submit" name="upload_firmware" class="button button-primary" value="Upload Firmware">
        </form>
    </div>
    <?php

    if (isset($_POST['upload_firmware'])) {
        if (!empty($_FILES['firmware_file']['tmp_name'])) {
            $upload_dir = wp_upload_dir();
            $firmware_dir = $upload_dir['basedir'] . '/firmware';
            if (!file_exists($firmware_dir)) {
                wp_mkdir_p($firmware_dir);
            }
            $filename = basename($_FILES['firmware_file']['name']);
            $target = $firmware_dir . '/' . $filename;

            if (move_uploaded_file($_FILES['firmware_file']['tmp_name'], $target)) {
                echo '<div class="updated"><p>Firmware berhasil diupload: ' . esc_html($filename) . '</p></div>';
                update_option('iot_firmware_latest', $upload_dir['baseurl'] . '/firmware/' . $filename);
                update_option('iot_firmware_version', time()); // versi sederhana timestamp
            } else {
                echo '<div class="error"><p>Upload gagal!</p></div>';
            }
        }
    }
}

// === REST API Endpoint untuk device OTA ===
add_action('rest_api_init', function () {
    register_rest_route('iot-firmware/v1', '/version/(?P<device>[\w-]+)', [
        'methods'  => 'GET',
        'callback' => 'iot_firmware_api',
    ]);
});

function iot_firmware_api(WP_REST_Request $request)
{
    $device = sanitize_text_field($request['device']);
    $url = get_option('iot_firmware_latest', '');
    $version = get_option('iot_firmware_version', '0');

    return [
        'device'  => $device,
        'version' => $version,
        'url'     => $url,
    ];
}

// === Custom Plugin Update from GitHub ===
add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }

    // URL ke update.json di GitHub
    $remote = wp_remote_get('https://raw.githubusercontent.com/topobash/iot-firmware-manager/main/update.json');
    if (is_wp_error($remote) || wp_remote_retrieve_response_code($remote) !== 200) {
        return $transient;
    }

    $data = json_decode(wp_remote_retrieve_body($remote));
    if (!$data) {
        return $transient;
    }

    // Versi plugin lokal
    $plugin_file = plugin_basename(__FILE__);
    $plugin_data = get_plugin_data(__FILE__);
    $local_version = $plugin_data['Version'];

    // Bandingkan versi
    if (version_compare($data->version, $local_version, '>')) {
        $transient->response[$plugin_file] = (object)[
            'slug'        => dirname($plugin_file),
            'new_version' => $data->version,
            'url'         => 'https://github.com/topobash/iot-firmware-manager',
            'package'     => $data->download_url,
        ];
    }

    return $transient;
});

// === Info plugin di halaman detail update ===
add_filter('plugins_api', function ($res, $action, $args) {
    if ($action !== 'plugin_information') {
        return $res;
    }

    if ($args->slug !== 'iot-firmware-manager') {
        return $res;
    }

    $remote = wp_remote_get('https://raw.githubusercontent.com/topobash/iot-firmware-manager/main/update.json');
    if (is_wp_error($remote) || wp_remote_retrieve_response_code($remote) !== 200) {
        return $res;
    }

    $data = json_decode(wp_remote_retrieve_body($remote));
    if (!$data) {
        return $res;
    }

    $res = (object)[
        'name'          => 'IoT Firmware Manager',
        'slug'          => 'iot-firmware-manager',
        'version'       => $data->version,
        'author'        => '<a href="https://cobaterus.com">Cobaterus IoT</a>',
        'homepage'      => 'https://github.com/topobash/iot-firmware-manager',
        'download_link' => $data->download_url,
        'sections'      => [
            'description' => 'Plugin untuk mengelola firmware IoT device.',
            'changelog'   => 'Update versi ' . $data->version,
        ],
    ];

    return $res;
}, 10, 3);
