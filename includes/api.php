<?php
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {
    register_rest_route('iot-firmware/v1', '/version/(?P<device>[a-zA-Z0-9_-]+)', [
        'methods' => 'GET',
        'callback' => 'iot_firmware_get_version',
        'permission_callback' => '__return_true'
    ]);
});

function iot_firmware_get_version($data)
{
    $device = sanitize_text_field($data['device']);
    $upload_dir = wp_upload_dir();
    $fw_dir = trailingslashit($upload_dir['basedir']) . 'firmware';
    $fw_url = trailingslashit($upload_dir['baseurl']) . 'firmware';
    $fw_file = $fw_dir . '/firmware.bin';
    $version_file = $fw_dir . '/version.txt';

    if (file_exists($fw_file)) {
        $version = file_exists($version_file) ? trim(file_get_contents($version_file)) : 'unknown';
        return [
            'device' => $device,
            'version' => $version,
            'url' => $fw_url . 'firmware.bin'
        ];
    } else {
        return new WP_Error('no_firmware', 'Firmware not found for device: ' . $device, ['status' => 404]);
    }
}
