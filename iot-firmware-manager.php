<?php
/*
Plugin Name: IoT Firmware Manager
Description: Upload firmware OTA, monitoring device, dan distribusi update ke IoT.
Version: 1.2.0
Author: cobaterus
*/

// ===== Buat tabel device saat plugin aktif =====
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

    // Buat option untuk metadata firmware
    if (!get_option('iot_firmware_meta')) {
        update_option('iot_firmware_meta', []);
    }
});

// ===== REST API: Report dari device =====
add_action('rest_api_init', function () {
    register_rest_route('iot-firmware/v1', '/report', [
        'methods' => 'POST',
        'callback' => function ($request) {
            global $wpdb;
            $table = $wpdb->prefix . 'iot_devices';

            $device_id  = sanitize_text_field($request['device_id']);
            $fw_version = sanitize_text_field($request['fw_version']);
            $status     = sanitize_text_field($request['status']);

            if (!$device_id) {
                return new WP_Error('no_device', 'Device ID required', ['status' => 400]);
            }

            // Upsert device
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

    // REST API: Device minta firmware terbaru
    register_rest_route('iot-firmware/v1', '/version/(?P<device_type>[a-zA-Z0-9_-]+)', [
        'methods' => 'GET',
        'callback' => function ($request) {
            $device_type = sanitize_text_field($request['device_type']);
            $meta = get_option('iot_firmware_meta', []);

            if (isset($meta[$device_type])) {
                return [
                    'version' => $meta[$device_type]['version'],
                    'url'     => $meta[$device_type]['url']
                ];
            } else {
                return new WP_Error('no_fw', 'No firmware found for device type', ['status' => 404]);
            }
        },
        'permission_callback' => '__return_true'
    ]);
});

// ===== Admin menu: IoT Devices =====
add_action('admin_menu', function () {
    add_menu_page(
        'IoT Devices',
        'IoT Devices',
        'manage_options',
        'iot-devices',
        'iot_devices_page',
        'dashicons-networking',
        6
    );

    add_menu_page(
        'IoT Firmware',
        'IoT Firmware',
        'manage_options',
        'iot-firmware',
        'iot_firmware_page',
        'dashicons-update',
        7
    );
});

function iot_devices_page()
{
    global $wpdb;
    $table = $wpdb->prefix . 'iot_devices';
    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY last_seen DESC");

    echo "<div class='wrap'><h1>IoT Devices</h1>";
    echo "<table class='widefat fixed striped'>
            <thead><tr>
                <th>Device ID</th><th>Firmware</th><th>Status</th><th>Last Seen</th>
            </tr></thead><tbody>";

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

// ===== Admin page: IoT Firmware Manager =====
function iot_firmware_page()
{
    if (!current_user_can('manage_options')) return;

    $upload_dir = wp_upload_dir();
    $fw_dir = $upload_dir['basedir'] . '/iot-firmware/';
    $fw_url = $upload_dir['baseurl'] . '/iot-firmware/';
    if (!file_exists($fw_dir)) wp_mkdir_p($fw_dir);

    $meta = get_option('iot_firmware_meta', []);

    // Handle upload
    if (isset($_POST['submit_fw']) && !empty($_FILES['firmware_file']['name'])) {
        $device_type = sanitize_text_field($_POST['device_type']);
        $fw_version  = sanitize_text_field($_POST['fw_version']);

        $file = $_FILES['firmware_file'];
        $uploaded = wp_handle_upload($file, ['test_form' => false]);

        if (isset($uploaded['file'])) {
            $filename = basename($uploaded['file']);
            $target = $fw_dir . $filename;
            rename($uploaded['file'], $target);

            $meta[$device_type] = [
                'version' => $fw_version,
                'url'     => $fw_url . $filename
            ];
            update_option('iot_firmware_meta', $meta);

            echo "<div class='updated'><p>Firmware uploaded: $filename</p></div>";
        } else {
            echo "<div class='error'><p>Upload failed.</p></div>";
        }
    }

    // Handle delete
    if (isset($_GET['delete'])) {
        $device_type = sanitize_text_field($_GET['delete']);
        if (isset($meta[$device_type])) {
            $file = basename($meta[$device_type]['url']);
            if (file_exists($fw_dir . $file)) unlink($fw_dir . $file);
            unset($meta[$device_type]);
            update_option('iot_firmware_meta', $meta);
            echo "<div class='updated'><p>Firmware for $device_type deleted.</p></div>";
        }
    }

    // UI
    echo "<div class='wrap'><h1>IoT Firmware Manager</h1>";

    echo "<form method='post' enctype='multipart/form-data'>
            <h3>Upload Firmware Baru</h3>
            <table class='form-table'>
              <tr><th scope='row'>Device Type</th>
                  <td><input type='text' name='device_type' required></td></tr>
              <tr><th scope='row'>Version</th>
                  <td><input type='text' name='fw_version' required></td></tr>
              <tr><th scope='row'>File (.bin)</th>
                  <td><input type='file' name='firmware_file' accept='.bin' required></td></tr>
            </table>
            <p><input type='submit' name='submit_fw' class='button button-primary' value='Upload Firmware'></p>
          </form><hr>";

    if ($meta) {
        echo "<h3>Firmware Tersedia</h3>
              <table class='widefat striped'>
                <thead><tr><th>Device Type</th><th>Version</th><th>URL</th><th>Action</th></tr></thead><tbody>";
        foreach ($meta as $type => $info) {
            echo "<tr>
                    <td>$type</td>
                    <td>{$info['version']}</td>
                    <td><a href='{$info['url']}' target='_blank'>{$info['url']}</a></td>
                    <td><a href='?page=iot-firmware&delete=$type'
                           onclick='return confirm(\"Delete firmware for $type?\")'>Delete</a></td>
                  </tr>";
        }
        echo "</tbody></table>";
    } else {
        echo "<p>Belum ada firmware yang diupload.</p>";
    }

    echo "</div>";
}
