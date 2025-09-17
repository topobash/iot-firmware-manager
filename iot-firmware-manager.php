<?php
/*
Plugin Name: IoT Firmware Manager
Description: Firmware OTA manager + device monitoring + GitHub auto updater.
Version: 1.0.0
Author: cobaterus
*/

defined('ABSPATH') || exit;

// === Constants ===
define('IOT_FIRMWARE_PLUGIN_VERSION', '1.0.0');
define('IOT_FIRMWARE_PLUGIN_SLUG', plugin_basename(__FILE__));
define('IOT_FIRMWARE_GITHUB_USER', 'topobash');
define('IOT_FIRMWARE_GITHUB_REPO', 'iot-firmware-manager');

// === Include Custom API ===
require_once plugin_dir_path(__FILE__) . 'includes/api.php';

// === GitHub Auto Updater ===
add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (empty($transient->checked)) return $transient;

    $remote = wp_remote_get("https://api.github.com/repos/" . IOT_FIRMWARE_GITHUB_USER . "/" . IOT_FIRMWARE_GITHUB_REPO . "/releases/latest", [
        'headers' => ['Accept' => 'application/vnd.github.v3+json']
    ]);

    if (!is_wp_error($remote) && $remote['response']['code'] == 200) {
        $body = json_decode(wp_remote_retrieve_body($remote), true);
        if (!empty($body['tag_name'])) {
            $remote_version = ltrim($body['tag_name'], 'v');
            if (version_compare(IOT_FIRMWARE_PLUGIN_VERSION, $remote_version, '<')) {
                $transient->response[IOT_FIRMWARE_PLUGIN_SLUG] = (object)[
                    'slug' => 'iot-firmware-manager',
                    'plugin' => IOT_FIRMWARE_PLUGIN_SLUG,
                    'new_version' => $remote_version,
                    'url' => $body['html_url'],
                    'package' => $body['zipball_url']
                ];
            }
        }
    }
    return $transient;
});

add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || $args->slug !== 'iot-firmware-manager') return $result;

    $remote = wp_remote_get("https://api.github.com/repos/" . IOT_FIRMWARE_GITHUB_USER . "/" . IOT_FIRMWARE_GITHUB_REPO . "/releases/latest", [
        'headers' => ['Accept' => 'application/vnd.github.v3+json']
    ]);

    if (!is_wp_error($remote) && $remote['response']['code'] == 200) {
        $body = json_decode(wp_remote_retrieve_body($remote), true);
        $res = new stdClass();
        $res->name = "IoT Firmware Manager";
        $res->slug = "iot-firmware-manager";
        $res->version = ltrim($body['tag_name'], 'v');
        $res->author = "cobaterus";
        $res->homepage = $body['html_url'];
        $res->download_link = $body['zipball_url'];
        $res->sections = [
            'description' => $body['body'] ?? 'IoT Firmware Manager plugin dengan auto update GitHub'
        ];
        return $res;
    }
    return $result;
}, 10, 3);

// === DB Table Setup ===
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $table = $wpdb->prefix . 'iot_devices';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        device_id VARCHAR(64) NOT NULL,
        fw_version VARCHAR(32) DEFAULT '',
        status VARCHAR(32) DEFAULT 'offline',
        last_seen DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
});

// === REST API for Reporting Device Info ===
add_action('rest_api_init', function () {
    register_rest_route('iot-firmware/v1', '/report', [
        'methods' => 'POST',
        'callback' => function ($req) {
            global $wpdb;
            $table = $wpdb->prefix . 'iot_devices';

            $device_id  = sanitize_text_field($req['device_id']);
            $fw_version = sanitize_text_field($req['fw_version']);
            $status     = sanitize_text_field($req['status']);

            if (!$device_id) {
                return new WP_Error('no_device', 'Device ID required', ['status' => 400]);
            }

            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE device_id=%s", $device_id));
            if ($exists) {
                $wpdb->update($table, [
                    'fw_version' => $fw_version,
                    'status' => $status,
                    'last_seen' => current_time('mysql')
                ], ['id' => $exists]);
            } else {
                $wpdb->insert($table, [
                    'device_id' => $device_id,
                    'fw_version' => $fw_version,
                    'status' => $status,
                    'last_seen' => current_time('mysql')
                ]);
            }

            return ['success' => true, 'device_id' => $device_id];
        },
        'permission_callback' => '__return_true'
    ]);
});

// === Admin Pages ===
add_action('admin_menu', function () {
    add_menu_page(
        'IoT Firmware',
        'IoT Firmware',
        'manage_options',
        'iot-firmware',
        'iot_firmware_page',
        'dashicons-upload',
        6
    );

    add_submenu_page(
        'iot-firmware',
        'IoT Devices',
        'IoT Devices',
        'manage_options',
        'iot-devices',
        'iot_devices_page'
    );
});

// === Admin Page: Firmware Upload ===
function iot_firmware_page()
{
    $upload_dir = wp_upload_dir();
    $fw_dir = $upload_dir['basedir'] . '/firmware/';
    $fw_url = $upload_dir['baseurl'] . '/firmware/';

    if (isset($_FILES['firmware_file'])) {
        $firmware_version = isset($_POST['firmware_version']) ? trim($_POST['firmware_version']) : '';

        if (empty($firmware_version)) {
            echo "<div class='error'><p><strong>❌ Gagal:</strong> Versi firmware wajib diisi.</p></div>";
        } else {
            if (!file_exists($fw_dir)) wp_mkdir_p($fw_dir);

            $dest = $fw_dir . 'firmware.bin';

            if (move_uploaded_file($_FILES['firmware_file']['tmp_name'], $dest)) {
                // Simpan versi ke version.txt
                file_put_contents($fw_dir . 'version.txt', sanitize_text_field($firmware_version));
                echo "<div class='updated'><p>✅ Firmware berhasil diupload! Versi: <strong>$firmware_version</strong></p></div>";
            } else {
                echo "<div class='error'><p>❌ Gagal upload firmware.</p></div>";
            }
        }
    }

    echo "<div class='wrap'><h1>Upload Firmware OTA</h1>
    <form method='post' enctype='multipart/form-data' style='max-width:500px;'>
        <p>
            <label for='firmware_file'><strong>File Firmware (.bin):</strong></label><br>
            <input type='file' name='firmware_file' id='firmware_file' accept='.bin' required>
        </p>
        <p>
            <label for='firmware_version'><strong>Versi Firmware:</strong></label><br>
            <input type='text' name='firmware_version' id='firmware_version' placeholder='Contoh: 1.0.0' required>
        </p>
        <p>
            <button type='submit' class='button button-primary'>Upload</button>
        </p>
    </form>
    <hr>";

    if (file_exists($fw_dir . 'firmware.bin')) {
        $url = $fw_url . 'firmware.bin';
        $version = file_exists($fw_dir . 'version.txt') ? trim(file_get_contents($fw_dir . 'version.txt')) : 'unknown';
        $time = date("Y-m-d H:i:s", filemtime($fw_dir . 'firmware.bin'));
        echo "<p><strong>Firmware Aktif:</strong> <a href='$url' target='_blank'>$url</a><br>";
        echo "Versi: $version<br>Last updated: $time</p>";
    } else {
        echo "<p><em>Belum ada firmware diupload.</em></p>";
    }

    echo "</div>";
}

// === Admin Page: Devices ===
function iot_devices_page()
{
    global $wpdb;
    $table = $wpdb->prefix . 'iot_devices';
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY last_seen DESC");

    echo "<div class='wrap'><h1>IoT Devices</h1>";
    echo "<table class='widefat fixed striped'><thead>
            <tr><th>Device ID</th><th>Firmware</th><th>Status</th><th>Last Seen</th></tr>
          </thead><tbody>";

    foreach ($rows as $r) {
        echo "<tr>
            <td>{$r->device_id}</td>
            <td>{$r->fw_version}</td>
            <td>{$r->status}</td>
            <td>{$r->last_seen}</td>
        </tr>";
    }

    echo "</tbody></table></div>";
}
