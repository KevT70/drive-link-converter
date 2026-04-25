<?php
if (isset($_GET['action']) && $_GET['action'] === 'activate') {
    error_reporting(E_ERROR | E_PARSE);
}
// Force all PHP errors into a custom log file we control
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/kwbg-error.log');

/*
Plugin Name: KWBG Drive Link Converter
Description: Converts Google Drive share links into streamable audio players using a service account. Provides a shortcode and an admin interface with proxy streaming and async transcription.
Author: Kev Thomas - Jan'26 - kev_thomas@hotmail.com

Version: 
2.4 - 04-02-26 - Updated Admin UI to remove unecessary fields or items
2.5 - 04-02-26 - Updated Admin menu to offer transcript editor functionality
2.6 - 06-02-26 - Updateed to add JS function to operate show/hide on transcript
*/

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error) {
        error_log("KWBG FATAL: " . json_encode($error));
    }
});

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Safe loader for Google API client
 */
function kwbg_load_google_client() {
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload) && !class_exists('Google_Client')) {
        require_once $autoload;
    }
}

/**
 * Plugin activation: create transcripts table and default options
 */
function kwbg_drive_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'kwbg_transcripts';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        file_id VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        transcript LONGTEXT NULL,
        error_message TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY file_id (file_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    add_option('kwbg_transcribe_api_url', 'https://nothingproject.ddns.net/transcribe');
    add_option('kwbg_transcribe_api_token', 'change-me');

    // Schedule recurring worker (every minute) if not already scheduled
    if (!wp_next_scheduled('kwbg_process_transcripts_event')) {
        wp_schedule_event(time() + 60, 'minute', 'kwbg_process_transcripts_event');
    }
}
register_activation_hook(__FILE__, 'kwbg_drive_activate');

// Ensure transcripts table has file_name column
function kwbg_drive_maybe_add_filename_column() {
    global $wpdb;
    $table = $wpdb->prefix . 'kwbg_transcripts';

    $column = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'file_name'");
    if (empty($column)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN file_name VARCHAR(255) NULL AFTER file_id");
    }
}
add_action('plugins_loaded', 'kwbg_drive_maybe_add_filename_column');

/**
 * Deactivation: clear cron
 */
function kwbg_drive_deactivate() {
    wp_clear_scheduled_hook('kwbg_process_transcripts_event');
}
register_deactivation_hook(__FILE__, 'kwbg_drive_deactivate');

/**
 * Add custom interval (1 minute) for cron
 */
function kwbg_cron_schedules($schedules) {
    if (!isset($schedules['minute'])) {
        $schedules['minute'] = [
            'interval' => 60,
            'display'  => __('Every Minute'),
        ];
    }
    return $schedules;
}
add_filter('cron_schedules', 'kwbg_cron_schedules');

/**
 * Extract Google Drive file ID from a share link
 */
function kwbg_extract_drive_id($url) {
    if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return $matches[1];
    }
    if (preg_match('/id=([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return $matches[1];
    }
    return false;
}

function kwbg_validate_drive_id($fileId, $client) {
    try {
        $drive = new Google_Service_Drive($client);
        $fileMeta = $drive->files->get($fileId, ['fields' => 'id']);
        return !empty($fileMeta->id);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Shortcode: [kwbg_drive_player url="https://drive.google.com/file/d/FILE_ID/view?usp=sharing"]
 * Shows audio player and transcript/placeholder.
 */
function kwbg_drive_player_shortcode($atts) {
    global $wpdb;
    $atts = shortcode_atts(['url' => ''], $atts);

    $fileId = kwbg_extract_drive_id($atts['url']);
    if (!$fileId) {
        return "<p style='color:red;'>Invalid Google Drive link.</p>";
    }

    $proxyUrl = home_url('/wp-json/kwbg/v1/proxy/' . $fileId);

    $table_name = $wpdb->prefix . 'kwbg_transcripts';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE file_id = %s", $fileId));

    // Ensure a transcript job exists — but ONLY if file_name is known.
    // This prevents rogue rows from old posts, malformed shortcodes, autosaves, crawlers, etc.
    if (!$row) {

        // Check if this file_id already has a known file_name (set by the admin converter)
        $fileName = $wpdb->get_var($wpdb->prepare(
            "SELECT file_name FROM $table_name WHERE file_id = %s",
            $fileId
        ));

        if (empty($fileName)) {
            // Do NOT create a transcript job for unknown or legacy shortcodes
            error_log("KWBG: Skipping transcript insert for unknown file_id=$fileId");
            return "<div class='kwbg-transcript-pending'><em>Transcript will be available after the link is processed.</em></div>";
        }

        // Safe to create a job — file_name is known
        $wpdb->insert($table_name, [
            'file_id'       => $fileId,
            'file_name'     => $fileName,
            'status'        => 'pending',
            'transcript'    => null,
            'error_message' => null,
            'created_at'    => current_time('mysql'),
            'updated_at'    => current_time('mysql'),
        ]);

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE file_id = %s", $fileId));
    }

    // Build transcript HTML safely
    if ($row && $row->status === 'complete' && !empty($row->transcript)) {

        $transcript_html = '
<div class="kwbg-transcript-wrapper">
    <button class="kwbg-transcript-toggle">Show Transcript</button>

    <div class="kwbg-transcript-block kwbg-hidden">
        <h4 class="kwbg-transcript-title">Transcript</h4>
        <div class="kwbg-transcript-text">
            ' . wpautop( esc_html( $row->transcript ) ) . '
        </div>
    </div>
</div>';

    } elseif ($row && $row->status === 'error') {

        $transcript_html = '<div class="kwbg-transcript-error"><em>Transcript unavailable.</em></div>';

    } else {

        $transcript_html = '<div class="kwbg-transcript-pending"><em>Audio transcript will be available soon.</em></div>';
    }

    // Wrap everything in a container
    $html  = '<div class="kwbg-drive-player">';
    $html .= '<audio id="kwbg_audio_' . esc_attr($fileId) . '" controls>';
    $html .= '<source src="' . esc_url($proxyUrl) . '" type="audio/mpeg">';
    $html .= '</audio>';
    $html .= $transcript_html;
    $html .= '</div>';

    // Prevent WP from auto-wrapping this shortcode in <p> tags
    return $html;
}

add_shortcode('kwbg_drive_player', 'kwbg_drive_player_shortcode');

add_filter('the_content', function($content) {
    // Remove <p> wrappers around the shortcode
    $content = preg_replace('/<p>\s*(\[kwbg_drive_player[^\]]+\])\s*<\/p>/', '$1', $content);
    return $content;
}, 8);

/**
 * Register admin menu
 */
function kwbg_drive_admin_menu() {
    add_menu_page(
        'KWBG Drive Link Converter',
        'KWBG Drive Link Converter',
        'manage_options',
        'kwbg-drive-link-converter',
        'kwbg_drive_admin_page',
        'dashicons-format-audio',
        100
    );
}
add_action('admin_menu', 'kwbg_drive_admin_menu');

// Register transcript management submenus
function kwbg_drive_register_transcript_menus() {

    // Visible submenu: Transcript List
    add_submenu_page(
        'kwbg-drive-link-converter',      // parent slug
        'Transcripts',                    // page title
        'Transcripts',                    // menu title
        'manage_options',                 // capability
        'kwbg-drive-transcripts',         // menu slug
        'kwbg_drive_transcript_list_page' // callback
    );

    // Hidden submenu: Edit Transcript (no menu entry)
    add_submenu_page(
        null,                             // no parent → hidden page
        'Edit Transcript',                // page title
        'Edit Transcript',                // menu title (unused)
        'manage_options',                 // capability
        'kwbg-drive-edit-transcript',     // slug
        'kwbg_drive_edit_transcript_page' // callback
    );
}
add_action('admin_menu', 'kwbg_drive_register_transcript_menus');

/* List the transcribed files for editing */
function kwbg_drive_transcript_list_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'kwbg_transcripts';

    $rows = $wpdb->get_results("SELECT * FROM $table ORDER BY updated_at DESC");

    echo '<div class="wrap">';
    echo '<h1>Transcripts</h1>';

    if (!$rows) {
        echo '<p>No transcripts found.</p></div>';
        return;
    }

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>
            <th>File Name</th>
            <th>File ID</th>
            <th>Status</th>
            <th>Updated</th>
            <th>Actions</th>
          </tr></thead><tbody>';

    foreach ($rows as $row) {
        $edit_url = admin_url('admin.php?page=kwbg-drive-edit-transcript&id=' . urlencode($row->file_id));

        echo '<tr>';
        echo '<td>' . esc_html($row->file_name ?: '(unknown)') . '</td>';
        echo '<td>' . esc_html($row->file_id) . '</td>';
        echo '<td>' . esc_html($row->status) . '</td>';
        echo '<td>' . esc_html($row->updated_at) . '</td>';
        echo '<td><a class="button" href="' . esc_url($edit_url) . '">Edit</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

/* Provide edit functions for select transciption */
function kwbg_drive_edit_transcript_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'kwbg_transcripts';

    $fileId = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';
    if (!$fileId) {
        wp_die('Missing file ID');
    }

    // Handle save
    if (!empty($_POST['kwbg_transcript'])) {
        check_admin_referer('kwbg_edit_transcript');

        $wpdb->update(
            $table,
            [
                'transcript' => wp_unslash($_POST['kwbg_transcript']),
                'status'     => 'complete',
                'updated_at' => current_time('mysql')
            ],
            ['file_id' => $fileId]
        );

        echo '<div class="updated"><p>Transcript saved.</p></div>';
    }

    // Load transcript row
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table WHERE file_id = %s", $fileId)
    );

    if (!$row) {
        wp_die('Transcript not found');
    }

    // Build proxy URL for audio preview
    $proxyUrl = home_url('/wp-json/kwbg/v1/proxy/' . $fileId);

    echo '<div class="wrap">';
    echo '<h1>Edit Transcript</h1>';

    echo '<h2>Audio Preview</h2>';
    echo '<audio controls style="width:100%; max-width:600px;">';
    echo '<source src="' . esc_url($proxyUrl) . '" type="audio/mpeg">';
    echo 'Your browser does not support the audio element.';
    echo '</audio>';

    echo '<form method="post" style="margin-top:20px;">';
    wp_nonce_field('kwbg_edit_transcript');

    echo '<h2>Transcript</h2>';
    echo '<textarea name="kwbg_transcript" rows="20" style="width:100%;">'
        . esc_textarea($row->transcript)
        . '</textarea>';

    echo '<p><input type="submit" class="button button-primary" value="Save Transcript"></p>';
    echo '</form>';

    echo '</div>';
}

function kwbg_admin_styles() {
    wp_enqueue_style(
        'kwbg-admin',
        plugin_dir_url(__FILE__) . 'css/kwbg-drive-converter.css',
        ['dashicons'],
        '1.1'
    );
}
add_action('admin_enqueue_scripts', 'kwbg_admin_styles');

function kwbg_drive_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'kwbg_transcripts';
    ?>
    <div class="wrap">
        <h1>KWBG Drive Link Converter</h1>
        <p>Paste a Google Drive share link below to generate a shortcode. Transcription will be processed in the background.</p>

        <form method="post" action="">
            <?php wp_nonce_field('kwbg_drive_convert', 'kwbg_drive_nonce'); ?>

            <label for="kwbg_drive_url">
                <span class="dashicons dashicons-admin-links"></span> Link URL
            </label><br>
            <input type="text" id="kwbg_drive_url" name="kwbg_drive_url" style="width: 80%;"
                   placeholder="https://drive.google.com/file/d/FILE_ID/view?usp=sharing" />
            <p class="description">Paste the full Google Drive share link including https://</p>

            <label for="kwbg_drive_urls" style="margin-top:15px; display:block;">
                <span class="dashicons dashicons-list-view"></span> Batch add links
            </label><br>
            <textarea id="kwbg_drive_urls" name="kwbg_drive_urls" rows="5" style="width:80%;"
                      placeholder="Paste multiple Google Drive links, one per line"></textarea>
            <p class="description">Batch mode: paste several links, each on its own line.</p>

            <input type="submit" class="button button-primary" value="Convert Link(s)" />
        </form>

        <?php
        // REMOVE live preview script entirely — no preview until after conversion.

        if ((isset($_POST['kwbg_drive_url']) || isset($_POST['kwbg_drive_urls'])) &&
            check_admin_referer('kwbg_drive_convert', 'kwbg_drive_nonce')) {

            $links = [];
            if (!empty($_POST['kwbg_drive_url'])) {
                $links[] = trim($_POST['kwbg_drive_url']);
            }
            if (!empty($_POST['kwbg_drive_urls'])) {
                $batch = preg_split('/\r\n|\r|\n/', $_POST['kwbg_drive_urls']);
                foreach ($batch as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $links[] = $line;
                    }
                }
            }

            echo '<h2>Results</h2>';

            echo '<div class="kwbg-success-banner">';
            echo '<span class="dashicons dashicons-yes"></span> ';
            echo count($links) . ' link(s) submitted';
            echo '</div>';

            kwbg_load_google_client();

            $client = new Google_Client();
            $client->setAuthConfig(__DIR__ . '/service-account.json');
            $client->setScopes([Google_Service_Drive::DRIVE_READONLY]);
            try {
                $client->fetchAccessTokenWithAssertion();
                $clientReady = true;
            } catch (Exception $e) {
                $clientReady = false;
                echo '<div class="kwbg-error-banner">';
                echo '<span class="dashicons dashicons-warning"></span> ';
                echo 'Service account error: ' . esc_html($e->getMessage());
                echo '</div>';
            }

            foreach ($links as $link) {
                $fileId = kwbg_extract_drive_id($link);
                $isDriveDomain = (strpos($link, 'drive.google.com') !== false);

                if (!$isDriveDomain || !$fileId) {
                    echo '<div class="kwbg-error-banner">';
                    echo '<span class="dashicons dashicons-warning"></span> ';
                    echo 'Invalid Google Drive link: ' . esc_html($link);
                    echo '</div>';
                    continue;
                }

                $isValid = false;
                $fileName = null;
                if ($clientReady) {
                    try {
                        $drive = new Google_Service_Drive($client);
                        $fileMeta = $drive->files->get($fileId, ['fields' => 'id, name']);
                        if (!empty($fileMeta->id)) {
                            $isValid = true;
                            if (method_exists($fileMeta, 'getName')) {
                                $fileName = $fileMeta->getName();
                            } elseif (isset($fileMeta['name'])) {
                                $fileName = $fileMeta['name'];
                            }
                        }
                    } catch (Exception $e) {
                        $isValid = false;
                    }
                }

                // Store filename in transcripts table
                $wpdb->update(
                    $table_name,
                    [
                        'file_name'  => $fileName,
                        'updated_at' => current_time('mysql')
                    ],
                    ['file_id' => $fileId]
                );

                if ($isValid) {
                    $proxyUrl = home_url('/wp-json/kwbg/v1/proxy/' . $fileId);
                    $shortcode = '[kwbg_drive_player url="' . esc_url($link) . '"]';

                    // Ensure transcript job exists
                    $existing = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $table_name 
                         WHERE file_id = %s 
                           AND status IN ('pending','complete','error') 
                           AND file_name IS NOT NULL
                         LIMIT 1",
                        $fileId
                    ));

                    if (!$existing) {
                        $wpdb->insert($table_name, [
                            'file_id'       => $fileId,
                            'file_name'     => $fileName,
                            'status'        => 'pending',
                            'transcript'    => null,
                            'error_message' => null,
                            'created_at'    => current_time('mysql'),
                            'updated_at'    => current_time('mysql'),
                        ]);
                    }

                    echo '<div class="kwbg-result-card">';
                    echo '<h3>Converted Link</h3>';

                    if ($fileName) {
                        echo '<p><strong>File Name:</strong> ' . esc_html($fileName) . '</p>';
                    }

                    echo '<p><strong>Original Link:</strong> ' . esc_html($link) . '</p>';

                    echo '<p><strong>Shortcode:</strong></p>';
                    echo '<input id="kwbg_shortcode_' . esc_attr($fileId) . '" type="text" readonly style="width: 80%;" value="' . esc_html($shortcode) . '" />';
                    echo '<button class="button" onclick="kwbgCopyToClipboard(\'kwbg_shortcode_' . esc_attr($fileId) . '\')">Copy</button>';

                    echo '<p><strong>Preview:</strong></p>';
                    echo '<audio controls style="width:100%; max-width:600px;">';
                    echo '<source src="' . esc_url($proxyUrl) . '" type="audio/mpeg">';
                    echo 'Your browser does not support the audio element.';
                    echo '</audio>';

                    echo '<p><em>Transcript will be processed in the background and appear under the player when ready.</em></p>';

                    echo '</div>';
                } else {
                    echo '<div class="kwbg-error-banner">';
                    echo '<span class="dashicons dashicons-warning"></span> ';
                    echo 'Invalid or inaccessible Google Drive file ID: ' . esc_html($link);
                    echo '</div>';
                }
            }
        }
        ?>
        <script>
        function kwbgCopyToClipboard(elementId) {
            var el = document.getElementById(elementId);
            if (!el) return;
            el.select();
            el.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(el.value).then(function() {
                alert("Copied: " + el.value);
            });
        }
        </script>
    </div>
    <?php
}

/**
 * AJAX Proxy Handler (legacy, still available)
 */
add_action('wp_ajax_kwbg_drive_proxy', 'kwbg_drive_proxy');
add_action('wp_ajax_nopriv_kwbg_drive_proxy', 'kwbg_drive_proxy');

function kwbg_drive_proxy() {
    if (!isset($_GET['fileId'])) {
        status_header(400);
        echo 'Missing fileId';
        exit;
    }
    $fileId = sanitize_text_field($_GET['fileId']);

    kwbg_load_google_client();

    if (function_exists('set_time_limit')) {
        @set_time_limit(0);
    }
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');

    try {
        $client = new Google_Client();
        $client->setAuthConfig(__DIR__ . '/service-account.json');
        $client->setScopes([Google_Service_Drive::DRIVE_READONLY]);
        $client->fetchAccessTokenWithAssertion();

        $drive = new Google_Service_Drive($client);

        $fileMeta = $drive->files->get($fileId, ['fields' => 'size, name, mimeType']);
        $size = null;
        $mimeType = null;

        if (is_object($fileMeta)) {
            if (method_exists($fileMeta, 'getSize')) {
                $size = $fileMeta->getSize();
            } elseif (isset($fileMeta['size'])) {
                $size = $fileMeta['size'];
            }
            if (method_exists($fileMeta, 'getMimeType')) {
                $mimeType = $fileMeta->getMimeType();
            } elseif (isset($fileMeta['mimeType'])) {
                $mimeType = $fileMeta['mimeType'];
            }
        } elseif (is_array($fileMeta)) {
            $size = isset($fileMeta['size']) ? $fileMeta['size'] : null;
            $mimeType = isset($fileMeta['mimeType']) ? $fileMeta['mimeType'] : null;
        }

        if (empty($mimeType)) {
            $mimeType = 'audio/mpeg';
        }

        $rangeHeader = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;
        $start = 0;
        $end = ($size !== null) ? ($size - 1) : null;
        $isPartial = false;

        if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            if ($m[1] !== '') {
                $start = (int)$m[1];
            }
            if ($m[2] !== '') {
                $end = (int)$m[2];
            }
            if ($end === null && $size !== null) {
                $end = $size - 1;
            }
            if ($size !== null) {
                if ($start < 0 || $start >= $size) {
                    status_header(416);
                    header('Content-Range: bytes */' . $size);
                    exit;
                }
                if ($end === null || $end >= $size) {
                    $end = $size - 1;
                }
            }
            if ($end !== null && $end >= $start) {
                $isPartial = true;
            }
        }

        if ($isPartial) {
            status_header(206);
        } else {
            status_header(200);
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: inline');
        header('Accept-Ranges: bytes');

        if ($size !== null) {
            if ($isPartial) {
                $length = ($end - $start) + 1;
                header('Content-Length: ' . $length);
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            } else {
                header('Content-Length: ' . $size);
            }
        }

        $accessToken = $client->getAccessToken();
        $token = is_array($accessToken) && isset($accessToken['access_token'])
            ? $accessToken['access_token']
            : (is_string($accessToken) ? $accessToken : null);

        if (!$token) {
            status_header(500);
            echo 'Failed to acquire access token';
            exit;
        }

        $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media';

        $headers = [
            'Authorization: Bearer ' . $token,
        ];
        if ($isPartial) {
            $headers[] = 'Range: bytes=' . $start . '-' . $end;
        }

        $ch = curl_init();
        $fp = fopen('php://output', 'wb');

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 0,              // allow long-running downloads
            CURLOPT_CONNECTTIMEOUT => 60,      // allow slow Google responses
            CURLOPT_LOW_SPEED_TIME => 0,     // optional: allow 5 minutes of silence
            CURLOPT_LOW_SPEED_LIMIT => 0       // optional: treat 1 byte/sec as alive
        ]);

        $ok = curl_exec($ch);

        if ($ok === false) {
            $err = curl_error($ch);
        }

        curl_close($ch);
        fclose($fp);
        exit;

    } catch (Exception $e) {
        status_header(500);
        echo 'Proxy error: ' . esc_html($e->getMessage());
        exit;
    }
}

/**
 * Transcription helper: send file to external API
 */
function kwbg_transcribe_audio($file_path, $mime_type = 'audio/mpeg') {
    $api_url   = get_option('kwbg_transcribe_api_url');
    $api_token = get_option('kwbg_transcribe_api_token');

    error_log('KWBG is calling: ' . $api_url);
  
    if (!file_exists($file_path)) {
        return ['error' => 'Audio file not found'];
    }

    $ch = curl_init();

    $postfields = [
        'file' => curl_file_create($file_path, $mime_type, basename($file_path)),
    ];

    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postfields,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $api_token,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 0,              // allow long-running jobs
        CURLOPT_CONNECTTIMEOUT => 60,      // allow slow startup
        CURLOPT_LOW_SPEED_TIME => 0,     // optional: allow 5 minutes of silence
        CURLOPT_LOW_SPEED_LIMIT => 0       // optional: treat 1 byte/sec as alive
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => 'cURL error: ' . $error];
    }

    $data = json_decode($response, true);

    if (!isset($data['text'])) {
        return ['error' => 'Invalid response from transcription server'];
    }

    return ['text' => $data['text']];
}

/**
 * Cron worker: process pending transcripts
 */
add_action('kwbg_process_transcripts_event', 'kwbg_process_transcripts');

function kwbg_process_transcripts() {
    global $wpdb;

    // REQUIRED for download_url() to exist in cron
    require_once ABSPATH . 'wp-admin/includes/file.php';

    $table_name = $wpdb->prefix . 'kwbg_transcripts';

    // Only pick jobs that are truly pending
    $jobs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE status = %s LIMIT 3",
        'pending'
    ));

    if (!$jobs) {
        return;
    }

    foreach ($jobs as $job) {

        // 🔥 Lock the job immediately to prevent duplicate processing
        $wpdb->update($table_name, [
            'status'     => 'processing',
            'updated_at' => current_time('mysql'),
        ], ['id' => $job->id]);

        $fileId = $job->file_id;

        // Use the REST proxy that we know works
        $proxyUrl = home_url('/wp-json/kwbg/v1/proxy/' . $fileId);

        $tmp = download_url($proxyUrl);
        if (is_wp_error($tmp)) {
            $wpdb->update($table_name, [
                'status'        => 'error',
                'error_message' => 'Failed to download audio: ' . $tmp->get_error_message(),
                'updated_at'    => current_time('mysql'),
            ], ['id' => $job->id]);
            continue;
        }

        $result = kwbg_transcribe_audio($tmp, 'audio/mpeg');
        @unlink($tmp);

        if (isset($result['error'])) {
            $wpdb->update($table_name, [
                'status'        => 'error',
                'error_message' => $result['error'],
                'updated_at'    => current_time('mysql'),
            ], ['id' => $job->id]);
        } else {
            $wpdb->update($table_name, [
                'status'        => 'complete',
                'transcript'    => $result['text'],
                'error_message' => null,
                'updated_at'    => current_time('mysql'),
            ], ['id' => $job->id]);
        }
    }
}

/**
 * REST proxy for Google Drive streaming
 */
add_action('rest_api_init', function () {
    register_rest_route('kwbg/v1', '/proxy/(?P<fileId>[a-zA-Z0-9_-]+)', [
        'methods'  => 'GET',
        'callback' => 'kwbg_rest_drive_proxy',
        'permission_callback' => '__return_true', // public endpoint
    ]);
});

// Patched 03MAR26 - Kev Thomas
// Fix for iOS playback issues
function kwbg_rest_drive_proxy($request) {
    $fileId = sanitize_text_field($request['fileId']);

    kwbg_load_google_client();

    try {
        $client = new Google_Client();
        $client->setAuthConfig(__DIR__ . '/service-account.json');
        $client->setScopes([Google_Service_Drive::DRIVE_READONLY]);

        // Fetch token
        $token = $client->fetchAccessTokenWithAssertion();
        if (isset($token['error'])) {
            return new WP_Error('google_auth_error', $token['error_description'], ['status' => 500]);
        }

        $accessToken = $client->getAccessToken();
        if (!isset($accessToken['access_token'])) {
            return new WP_Error('google_token_missing', 'Missing Google access token', ['status' => 500]);
        }

        // Build Google Drive media URL
        $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media';

        // Prepare headers for Google request
        $headers = [
            'Authorization: Bearer ' . $accessToken['access_token'],
        ];

        // Forward Range header directly to Google (critical for iOS)
        if (isset($_SERVER['HTTP_RANGE'])) {
            $headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
        }

        // cURL setup
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_LOW_SPEED_TIME => 0,     // optional: allow 5 minutes of silence
            CURLOPT_LOW_SPEED_LIMIT => 0,    // optional: treat 1 byte/sec as "still alive"
            CURLOPT_HEADERFUNCTION => function($curl, $header) {
                // Forward ALL headers from Google to the client
                $len = strlen($header);
                $header = trim($header);

                if ($header !== '') {
                    header($header);
                }

                return $len;
            },
            CURLOPT_WRITEFUNCTION => function($curl, $data) {
                echo $data;
                return strlen($data);
            }
        ]);

        curl_exec($ch);
        curl_close($ch);
        exit;

    } catch (Exception $e) {
        return new WP_Error('proxy_error', $e->getMessage(), ['status' => 500]);
    }
}

/**
 * Front-end assets (Plyr)
 */
function kwbg_enqueue_plyr_assets() {
    wp_enqueue_style(
        'plyr-core',
        plugin_dir_url(__FILE__) . 'css/plyr.css',
        [],
        '3.7.8'
    );

    wp_enqueue_style(
        'kwbg-drive-player',
        plugin_dir_url(__FILE__) . 'css/kwbg-drive-player.css',
        ['plyr-core'],
        '1.19'
    );

    wp_enqueue_script(
        'plyr-js',
        plugin_dir_url(__FILE__) . 'js/plyr.min.js',
        [],
        '3.7.8',
        true
    );

    wp_enqueue_script(
        'kwbg-init',
        plugin_dir_url(__FILE__) . 'js/kwbg-init.js',
        ['plyr-js'],
        '1.0',
        true
    );
}
add_action('wp_enqueue_scripts', 'kwbg_enqueue_plyr_assets');


/*
* Add toggle for the Show/Hide transcript button on the player
*/
function kwbg_transcript_toggle_script() {
    ?>
    <script>
    document.addEventListener("click", function(event) {
        const btn = event.target.closest(".kwbg-transcript-toggle");
        if (!btn) return;

        const wrapper = btn.closest(".kwbg-transcript-wrapper");
        if (!wrapper) return;

        const block = wrapper.querySelector(".kwbg-transcript-block");
        if (!block) return;

        const isHidden = block.classList.contains("kwbg-hidden");

        if (isHidden) {
            block.classList.remove("kwbg-hidden");
            btn.textContent = "Hide Transcript";
        } else {
            block.classList.add("kwbg-hidden");
            btn.textContent = "Show Transcript";
        }
    });
    </script>
    <?php
}
add_action('wp_footer', 'kwbg_transcript_toggle_script');