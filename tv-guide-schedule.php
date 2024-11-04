<?php
/*
 Plugin Name: SMG TV Schedule
 Description: A plugin to show daily schedules for upcoming shows.
 Version: 1.1
 Author: Russell Stevenson
 Plugin URI: https://stevensonmediagroup.ca/shows
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Register the "Show" custom post type.
function tv_guide_register_show_post_type() {
    $labels = array(
        'name'               => 'Shows',
        'singular_name'      => 'Show',
        'menu_name'          => 'TV Guide',
        'name_admin_bar'     => 'Show',
        'add_new'            => 'Add New Show',
        'add_new_item'       => 'Add New Show',
        'new_item'           => 'New Show',
        'edit_item'          => 'Edit Show',
        'view_item'          => 'View Show',
        'all_items'          => 'All Shows',
        'search_items'       => 'Search Shows',
        'not_found'          => 'No shows found.',
        'not_found_in_trash' => 'No shows found in Trash.',
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'has_archive'        => true,
        'menu_position'      => 5,
        'menu_icon'          => 'dashicons-calendar-alt',
        'supports'           => array( 'title', 'editor', 'custom-fields', 'thumbnail' ),
        'rewrite'            => array( 'slug' => 'shows' ),
        'show_in_rest'       => true,
    );

    register_post_type( 'show', $args );
}
add_action( 'init', 'tv_guide_register_show_post_type' );

// Add custom meta boxes for start and end times.
function tv_guide_add_show_meta_boxes() {
    add_meta_box(
        'tv_guide_show_schedule',
        'Show Schedule',
        'tv_guide_show_schedule_callback',
        'show',
        'side',
        'default'
    );
}
add_action( 'add_meta_boxes', 'tv_guide_add_show_meta_boxes' );

// Callback function to render the meta box fields.
function tv_guide_show_schedule_callback( $post ) {
    wp_nonce_field( 'tv_guide_save_show_schedule', 'tv_guide_show_schedule_nonce' );

    $start_time = get_post_meta( $post->ID, '_tv_guide_show_start_time', true );
    $end_time = get_post_meta( $post->ID, '_tv_guide_show_end_time', true );

    echo '<label for="tv_guide_show_start_time">Start Time:</label>';
    echo '<input type="time" id="tv_guide_show_start_time" name="tv_guide_show_start_time" value="' . esc_attr( $start_time ) . '" />';
    echo '<br><br>';
    
    echo '<label for="tv_guide_show_end_time">End Time:</label>';
    echo '<input type="time" id="tv_guide_show_end_time" name="tv_guide_show_end_time" value="' . esc_attr( $end_time ) . '" />';
}

// Save the start and end times when the post is saved.
function tv_guide_save_show_schedule( $post_id ) {
    if ( !isset($_POST['tv_guide_show_schedule_nonce']) || !wp_verify_nonce($_POST['tv_guide_show_schedule_nonce'], 'tv_guide_save_show_schedule') ) {
        return;
    }

    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( !current_user_can('edit_post', $post_id) ) return;

    if ( isset($_POST['tv_guide_show_start_time']) ) {
        update_post_meta( $post_id, '_tv_guide_show_start_time', sanitize_text_field($_POST['tv_guide_show_start_time']) );
    }
    if ( isset($_POST['tv_guide_show_end_time']) ) {
        update_post_meta( $post_id, '_tv_guide_show_end_time', sanitize_text_field($_POST['tv_guide_show_end_time']) );
    }
}
add_action( 'save_post', 'tv_guide_save_show_schedule' );

// Shortcode to display today's schedule of shows.
function tv_guide_display_schedule() {
    // Get today's shows
    $today = current_time('Y-m-d');
    $args = array(
        'post_type' => 'show',
        'meta_query' => array(
            array(
                'key' => '_tv_guide_show_start_time',
                'compare' => 'EXISTS'
            ),
        ),
        'orderby' => 'meta_value',
        'meta_key' => '_tv_guide_show_start_time',
        'order' => 'ASC',
        'posts_per_page' => -1,
    );

    $query = new WP_Query( $args );
    if ( !$query->have_posts() ) {
        return '<p>No shows scheduled for today.</p>';
    }

    ob_start();

    echo '<div class="tv-guide-schedule">';
    echo '<h2>Today\'s Shows</h2>';
    echo '<ul>';

    while ( $query->have_posts() ) {
        $query->the_post();

        $title = get_the_title();
        $start_time = get_post_meta( get_the_ID(), '_tv_guide_show_start_time', true );
        $end_time = get_post_meta( get_the_ID(), '_tv_guide_show_end_time', true );
        $description = get_the_content();
        $thumbnail = get_the_post_thumbnail();

        echo '<li>';
        echo $thumbnail; // Display the show's thumbnail
        echo '<strong>' . esc_html( $title ) . '</strong><br>';
        echo 'Time: ' . esc_html( date('g:i A', strtotime($start_time)) ) . ' - ' . esc_html( date('g:i A', strtotime($end_time)) ) . '<br>';
        echo esc_html( $description );
        echo '</li>';
    }

    echo '</ul>';
    echo '</div>';

    wp_reset_postdata();

    return ob_get_clean();
}
add_shortcode( 'tv_guide_schedule', 'tv_guide_display_schedule' );

// Enqueue styles
function tv_guide_enqueue_styles() {
    if ( is_singular() && has_shortcode( get_post()->post_content, 'tv_guide_schedule') ) {
        wp_enqueue_style( 'tv-guide-schedule', plugins_url( 'style.css', __FILE__ ) );
    }
}
add_action( 'wp_enqueue_scripts', 'tv_guide_enqueue_styles' );

// GitHub Update Mechanism
add_filter('pre_set_site_transient_update_plugins', 'smg_check_for_update');
function smg_check_for_update($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }

    $plugin_slug = 'wp-tv-schedule/smg-tv-schedule.php'; // Update with your plugin path
    $response = wp_remote_get('https://api.github.com/repos/stevensonmediagroup/WP-TV-Schedule/releases/latest');

    if (!is_wp_error($response) && isset($response['body'])) {
        $data = json_decode($response['body']);
        
        if (isset($data->tag_name)) {
            $latest_version = $data->tag_name;
            if (version_compare($latest_version, $transient->checked[$plugin_slug], '>')) {
                $transient->response[$plugin_slug] = array(
                    'slug' => 'smg-tv-schedule',
                    'new_version' => $latest_version,
                    'url' => 'https://github.com/stevensonmediagroup/WP-TV-Schedule', // Repository URL
                    'package' => $data->zipball_url,
                );
            }
        }
    }

    return $transient;
}
