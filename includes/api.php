<?php
if (! defined('ABSPATH')) exit;

// Endpoint: /wp-json/iot-firmware/v1/version/{device}
add_action('rest_api_init', function () {
    register_rest_route('iot-firmware/v1', '/version/(?P<device>[a-zA-Z0-9_-]+)', array(
        'methods' => 'GET',
        'callback' => 'iot_firmware_get_version',
    ));
});

function iot_firmware_get_version($data)
{
    $device = sanitize_text_field($data['device']);
    $args = array(
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => 1,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => array(
            array(
                'key' => '_wp_attached_file',
                'value' => $device,
                'compare' => 'LIKE'
            )
        )
    );
    $query = get_posts($args);

    if ($query) {
        $file = wp_get_attachment_url($query[0]->ID);
        $version = basename($file);
        return array(
            'device'  => $device,
            'latest'  => $version,
            'url'     => $file
        );
    } else {
        return array('error' => 'Firmware not found for device ' . $device);
    }
}
