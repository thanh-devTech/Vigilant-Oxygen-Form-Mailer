<?php
/**
 * Plugin Name: Vigilant Oxygen Form Mailer
 * Description: Ensures Oxygen/Breakdance form emails notify Vigilant recipients and BCC list without changing Oxygen core files.
 * Version: 1.2.0
 * Author: CI Web Studio
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VIGILANT_OXYGEN_FORM_MAILER_OPTION', 'vigilant_oxygen_form_mailer_settings');
define('VIGILANT_OXYGEN_FORM_MAILER_DB_VERSION', '1.1.0');
define('VIGILANT_OXYGEN_FORM_MAILER_DB_OPTION', 'vigilant_oxygen_form_mailer_db_version');

function vigilant_oxygen_form_mailer_default_settings()
{
    return [
        'to_email' => 'info@vigilant-inc.com',
        'bcc_emails' => '',
        'from_email' => 'spillari@gmail.com',
        'from_name' => 'Vigilant Technologies',
        'admin_subject' => 'Thanks for Your Interest in Vigilant',
        'send_customer_receipt' => '1',
        'customer_subject' => 'Thank you — we’ve received your submission',
        'customer_message' => "Thank you for reaching out to Vigilant 360. Your submission has been received and is currently being reviewed by our team.\n\nWe’ll make sure the right professional follows up with you as soon as possible.\n\nIf you need assistance in the meantime or would like to talk to us immediately, please contact me directly at 313-715-6988 or swebster@vigilant-inc.com.\n\nBest regards,\nSarah Webster\nChief Marketing Officer\nVigilant 360",
    ];
}

function vigilant_oxygen_form_mailer_table_name()
{
    global $wpdb;

    return $wpdb->prefix . 'vigilant_form_submissions';
}

function vigilant_oxygen_form_mailer_install_table()
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table = vigilant_oxygen_form_mailer_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    dbDelta("CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        source varchar(40) NOT NULL DEFAULT '',
        source_id varchar(100) NOT NULL DEFAULT '',
        form_id varchar(100) NOT NULL DEFAULT '',
        form_name varchar(191) NOT NULL DEFAULT '',
        source_url text NULL,
        customer_email varchar(191) NOT NULL DEFAULT '',
        customer_name varchar(191) NOT NULL DEFAULT '',
        fields_json longtext NULL,
        fields_text longtext NULL,
        ip varchar(100) NOT NULL DEFAULT '',
        user_agent text NULL,
        admin_email_sent tinyint(1) NOT NULL DEFAULT 0,
        customer_email_sent tinyint(1) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY source_lookup (source, source_id),
        KEY customer_email (customer_email),
        KEY created_at (created_at)
    ) {$charset_collate};");

    update_option(VIGILANT_OXYGEN_FORM_MAILER_DB_OPTION, VIGILANT_OXYGEN_FORM_MAILER_DB_VERSION);
}

register_activation_hook(__FILE__, function () {
    if (!get_option(VIGILANT_OXYGEN_FORM_MAILER_OPTION)) {
        add_option(VIGILANT_OXYGEN_FORM_MAILER_OPTION, vigilant_oxygen_form_mailer_default_settings());
    }

    vigilant_oxygen_form_mailer_install_table();
});

add_action('plugins_loaded', function () {
    if (get_option(VIGILANT_OXYGEN_FORM_MAILER_DB_OPTION) !== VIGILANT_OXYGEN_FORM_MAILER_DB_VERSION) {
        vigilant_oxygen_form_mailer_install_table();
    }

    $settings = get_option(VIGILANT_OXYGEN_FORM_MAILER_OPTION);

    if (!is_array($settings)) {
        update_option(VIGILANT_OXYGEN_FORM_MAILER_OPTION, vigilant_oxygen_form_mailer_default_settings());
        return;
    }

    $defaults = vigilant_oxygen_form_mailer_default_settings();
    $old_defaults = [
        'from_email' => 'info@vigilant-inc.com',
        'from_name' => 'Vigilant Website',
        'customer_subject' => 'Thank you for contacting Vigilant',
        'customer_message' => "Thank you for contacting Vigilant. We received your message and our team will follow up with you soon.",
    ];
    $changed = false;

    foreach ($old_defaults as $key => $old_value) {
        if (!isset($settings[$key]) || $settings[$key] === $old_value) {
            $settings[$key] = $defaults[$key];
            $changed = true;
        }
    }

    if (!isset($settings['admin_subject'])) {
        $settings['admin_subject'] = $defaults['admin_subject'];
        $changed = true;
    }

    if ($changed) {
        update_option(VIGILANT_OXYGEN_FORM_MAILER_OPTION, $settings);
    }
});

function vigilant_oxygen_form_mailer_get_settings()
{
    $settings = get_option(VIGILANT_OXYGEN_FORM_MAILER_OPTION, []);

    return wp_parse_args(is_array($settings) ? $settings : [], vigilant_oxygen_form_mailer_default_settings());
}

function vigilant_oxygen_form_mailer_parse_emails($value)
{
    $raw_emails = is_array($value) ? $value : [$value];
    $emails = [];

    foreach ($raw_emails as $raw_email) {
        $emails = array_merge($emails, preg_split('/[,;\r\n]+/', (string) $raw_email) ?: []);
    }

    $emails = array_map('trim', $emails ?: []);
    $emails = array_filter($emails, 'is_email');

    return array_values(array_unique($emails));
}

function vigilant_oxygen_form_mailer_header_has_email($headers, $header_name, $email)
{
    foreach ((array) $headers as $header) {
        if (!is_string($header) || stripos($header, $header_name . ':') !== 0) {
            continue;
        }

        if (stripos($header, $email) !== false) {
            return true;
        }
    }

    return false;
}

function vigilant_oxygen_form_mailer_has_valid_from($headers)
{
    foreach ((array) $headers as $header) {
        if (!is_string($header) || stripos($header, 'From:') !== 0) {
            continue;
        }

        return (bool) preg_match('/<([^>]+)>/', $header, $matches) && is_email($matches[1]);
    }

    return false;
}

function vigilant_oxygen_form_mailer_ensure_from_header($headers, $from_name, $from_email)
{
    if (!is_email($from_email)) {
        return $headers;
    }

    $from_header = sprintf('From: %s <%s>', sanitize_text_field($from_name), sanitize_email($from_email));
    $has_from = false;

    foreach ($headers as $index => $header) {
        if (!is_string($header) || stripos($header, 'From:') !== 0) {
            continue;
        }

        $has_from = true;

        if (!vigilant_oxygen_form_mailer_has_valid_from([$header])) {
            $headers[$index] = $from_header;
        }
    }

    if (!$has_from) {
        $headers[] = $from_header;
    }

    return $headers;
}

function vigilant_oxygen_form_mailer_add_form_headers($headers)
{
    $GLOBALS['vigilant_oxygen_form_mailer_next_wp_mail'] = true;

    $settings = vigilant_oxygen_form_mailer_get_settings();
    $headers = (array) $headers;
    $bcc_emails = vigilant_oxygen_form_mailer_parse_emails($settings['bcc_emails']);

    if ($bcc_emails) {
        foreach ($bcc_emails as $email) {
            if (!vigilant_oxygen_form_mailer_header_has_email($headers, 'Bcc', $email)) {
                $headers[] = 'Bcc: ' . $email;
            }
        }
    }

    $headers = vigilant_oxygen_form_mailer_ensure_from_header($headers, $settings['from_name'], $settings['from_email']);

    return $headers;
}
add_filter('breakdance_email_headers', 'vigilant_oxygen_form_mailer_add_form_headers', 1000);

function vigilant_oxygen_form_mailer_adjust_wp_mail($args)
{
    if (empty($GLOBALS['vigilant_oxygen_form_mailer_next_wp_mail'])) {
        return $args;
    }

    unset($GLOBALS['vigilant_oxygen_form_mailer_next_wp_mail']);

    $settings = vigilant_oxygen_form_mailer_get_settings();
    $to_emails = vigilant_oxygen_form_mailer_parse_emails($args['to'] ?? []);
    $main_to = sanitize_email($settings['to_email']);
    $subject = sanitize_text_field($args['subject'] ?? '');
    $is_customer_receipt = $subject === sanitize_text_field($settings['customer_subject']);

    if (!$is_customer_receipt && is_email($main_to) && !in_array($main_to, $to_emails, true)) {
        $to_emails[] = $main_to;
    }

    if ($to_emails) {
        $args['to'] = $to_emails;
    }

    $args['headers'] = vigilant_oxygen_form_mailer_add_form_headers($args['headers'] ?? []);
    unset($GLOBALS['vigilant_oxygen_form_mailer_next_wp_mail']);

    return $args;
}
add_filter('wp_mail', 'vigilant_oxygen_form_mailer_adjust_wp_mail', 20);

function vigilant_oxygen_form_mailer_get_first_customer_email($form)
{
    foreach ((array) $form as $field) {
        if (($field['type'] ?? '') !== 'email') {
            continue;
        }

        $email = sanitize_email($field['value'] ?? '');

        if (is_email($email)) {
            return [$email, $field['advanced']['id'] ?? ''];
        }
    }

    return ['', ''];
}

function vigilant_oxygen_form_mailer_flatten_value($value)
{
    if (is_scalar($value) || $value === null) {
        return trim((string) $value);
    }

    $items = [];

    foreach ((array) $value as $item) {
        $item = vigilant_oxygen_form_mailer_flatten_value($item);

        if ($item !== '') {
            $items[] = $item;
        }
    }

    return implode(' ', $items);
}

function vigilant_oxygen_form_mailer_pretty_label($key)
{
    $key = preg_replace('/[\[\]_\\-]+/', ' ', (string) $key);
    $key = trim(preg_replace('/\s+/', ' ', $key));

    if ($key === '') {
        return 'Field';
    }

    $lower_key = strtolower($key);
    $labels = [
        'fname' => 'First Name',
        'first name' => 'First Name',
        'lname' => 'Last Name',
        'last name' => 'Last Name',
        'name' => 'Name',
        'names' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'company' => 'Company',
        'role' => 'Role',
        'message' => 'Message',
    ];

    return $labels[$lower_key] ?? ucwords($key);
}

function vigilant_oxygen_form_mailer_is_public_field($field)
{
    $field = (array) $field;
    $key = strtolower(trim((string) ($field['key'] ?? '')));
    $label = strtolower(trim((string) ($field['label'] ?? '')));
    $blocked_fields = ['uiiotk'];

    return !in_array($key, $blocked_fields, true) && !in_array($label, $blocked_fields, true);
}

function vigilant_oxygen_form_mailer_flatten_fields($data, $prefix = '')
{
    $fields = [];

    foreach ((array) $data as $key => $value) {
        $key = (string) $key;

        if ($key === '' || $key[0] === '_' || strtolower($key) === 'uiiotk') {
            continue;
        }

        $label = $prefix ? $prefix . ' ' . vigilant_oxygen_form_mailer_pretty_label($key) : vigilant_oxygen_form_mailer_pretty_label($key);

        if (is_array($value) || is_object($value)) {
            $value_array = (array) $value;
            $name_parts = array_filter([
                vigilant_oxygen_form_mailer_flatten_value($value_array['first_name'] ?? ''),
                vigilant_oxygen_form_mailer_flatten_value($value_array['middle_name'] ?? ''),
                vigilant_oxygen_form_mailer_flatten_value($value_array['last_name'] ?? ''),
            ]);

            if (!$prefix && $name_parts) {
                $fields[] = [
                    'key' => $key,
                    'label' => $label,
                    'value' => implode(' ', $name_parts),
                ];
                continue;
            }

            $children = vigilant_oxygen_form_mailer_flatten_fields($value_array, $label);

            if ($children) {
                $fields = array_merge($fields, $children);
                continue;
            }
        }

        $fields[] = [
            'key' => $key,
            'label' => $label,
            'value' => vigilant_oxygen_form_mailer_flatten_value($value),
        ];
    }

    return $fields;
}

function vigilant_oxygen_form_mailer_fields_text($fields)
{
    $lines = [];

    foreach ((array) $fields as $field) {
        if (!vigilant_oxygen_form_mailer_is_public_field($field)) {
            continue;
        }

        $label = trim((string) ($field['label'] ?? 'Field'));
        $value = vigilant_oxygen_form_mailer_flatten_value($field['value'] ?? '');

        if ($label === '' || $value === '') {
            continue;
        }

        $lines[] = $label . ': ' . $value;
    }

    return implode("\n", $lines);
}

function vigilant_oxygen_form_mailer_find_field_value($fields, $needles)
{
    foreach ((array) $fields as $field) {
        $haystack = strtolower(($field['key'] ?? '') . ' ' . ($field['label'] ?? ''));

        foreach ((array) $needles as $needle) {
            if (strpos($haystack, strtolower($needle)) !== false) {
                $value = vigilant_oxygen_form_mailer_flatten_value($field['value'] ?? '');

                if ($value !== '') {
                    return $value;
                }
            }
        }
    }

    return '';
}

function vigilant_oxygen_form_mailer_source_url_from_request($fallback = '')
{
    $url = $fallback;

    if (!$url && !empty($_SERVER['HTTP_REFERER'])) {
        $url = wp_unslash($_SERVER['HTTP_REFERER']);
    }

    if (!$url && !empty($_REQUEST['_wp_http_referer'])) {
        $url = wp_unslash($_REQUEST['_wp_http_referer']);
    }

    if ($url && strpos($url, 'http') !== 0) {
        $url = home_url($url);
    }

    return $url ? esc_url_raw($url) : '';
}

function vigilant_oxygen_form_mailer_build_admin_body($submission)
{
    $fields_text = trim((string) ($submission['fields_text'] ?? ''));
    $source_url = trim((string) ($submission['source_url'] ?? ''));
    $page_title = '';

    if ($source_url && function_exists('url_to_postid')) {
        $post_id = url_to_postid($source_url);

        if (!$post_id) {
            $source_path = wp_parse_url($source_url, PHP_URL_PATH);
            $post_id = $source_path ? url_to_postid(home_url($source_path)) : 0;
        }

        if ($post_id) {
            $page_title = get_the_title($post_id);
        }
    }

    $body = $fields_text !== '' ? $fields_text : 'No form fields were submitted.';
    $body .= "\n\n-----------------\nThis is a notification that a contact form was submitted on your\nwebsite";

    if ($source_url !== '') {
        $body .= ' (' . $source_url . ')';
    }

    $body .= '.';

    if ($page_title !== '') {
        $body .= ' ' . sanitize_text_field($page_title);
    }

    return $body;
}

function vigilant_oxygen_form_mailer_store_submission($submission)
{
    global $wpdb;

    $fields = array_values(array_filter((array) ($submission['fields'] ?? []), 'vigilant_oxygen_form_mailer_is_public_field'));
    $fields_text = vigilant_oxygen_form_mailer_fields_text($fields);
    $source_url = vigilant_oxygen_form_mailer_source_url_from_request($submission['source_url'] ?? '');
    $source_id = (string) ($submission['source_id'] ?? '');

    if ($source_id === '') {
        $source_id = md5(($submission['source'] ?? '') . '|' . $source_url . '|' . wp_json_encode($fields));
    }

    $row = [
        'source' => sanitize_text_field($submission['source'] ?? ''),
        'source_id' => sanitize_text_field($source_id),
        'form_id' => sanitize_text_field($submission['form_id'] ?? ''),
        'form_name' => sanitize_text_field($submission['form_name'] ?? ''),
        'source_url' => $source_url,
        'customer_email' => sanitize_email($submission['customer_email'] ?? ''),
        'customer_name' => sanitize_text_field($submission['customer_name'] ?? ''),
        'fields_json' => wp_json_encode($fields),
        'fields_text' => $fields_text,
        'ip' => sanitize_text_field($submission['ip'] ?? ''),
        'user_agent' => sanitize_text_field($submission['user_agent'] ?? ''),
        'admin_email_sent' => 0,
        'customer_email_sent' => 0,
        'created_at' => current_time('mysql'),
    ];

    $wpdb->insert(vigilant_oxygen_form_mailer_table_name(), $row);

    return [
        'id' => (int) $wpdb->insert_id,
        'row' => $row,
    ];
}

function vigilant_oxygen_form_mailer_send_admin_notification($submission)
{
    $settings = vigilant_oxygen_form_mailer_get_settings();
    $to_email = sanitize_email($settings['to_email']);

    if (!is_email($to_email)) {
        return false;
    }

    $from_email = is_email($settings['from_email']) ? sanitize_email($settings['from_email']) : 'spillari@gmail.com';
    $headers = [
        sprintf('From: %s <%s>', sanitize_text_field($settings['from_name']), $from_email),
        'Content-Type: text/plain; charset=UTF-8',
    ];
    $customer_email = sanitize_email($submission['customer_email'] ?? '');

    if ($customer_email) {
        $headers[] = 'Reply-To: ' . $customer_email;
    }

    return wp_mail(
        $to_email,
        sanitize_text_field($settings['admin_subject']),
        vigilant_oxygen_form_mailer_build_admin_body($submission),
        $headers
    );
}

function vigilant_oxygen_form_mailer_handle_submission($submission)
{
    global $wpdb;

    $fields = array_values(array_filter((array) ($submission['fields'] ?? []), 'vigilant_oxygen_form_mailer_is_public_field'));
    $submission['fields'] = $fields;
    $source_url = vigilant_oxygen_form_mailer_source_url_from_request($submission['source_url'] ?? '');
    $dedupe_key = md5(($submission['source'] ?? '') . '|' . ($submission['source_id'] ?? '') . '|' . $source_url . '|' . wp_json_encode($fields));

    if (!empty($GLOBALS['vigilant_oxygen_form_mailer_submissions_handled'][$dedupe_key])) {
        return $GLOBALS['vigilant_oxygen_form_mailer_submissions_handled'][$dedupe_key];
    }

    $submission['source_url'] = $source_url;
    $stored = vigilant_oxygen_form_mailer_store_submission($submission);
    $stored['row']['id'] = $stored['id'];
    $admin_sent = vigilant_oxygen_form_mailer_send_admin_notification($stored['row']);
    $customer_sent = !empty($submission['customer_email']) ? vigilant_oxygen_form_mailer_send_customer_receipt_email($submission['customer_email'], $stored['row']) : false;

    if ($stored['id']) {
        $wpdb->update(
            vigilant_oxygen_form_mailer_table_name(),
            [
                'admin_email_sent' => $admin_sent ? 1 : 0,
                'customer_email_sent' => $customer_sent ? 1 : 0,
            ],
            ['id' => $stored['id']]
        );
    }

    $GLOBALS['vigilant_oxygen_form_mailer_submissions_handled'][$dedupe_key] = $stored;

    return $stored;
}

function vigilant_oxygen_form_mailer_breakdance_fields($form, $extra)
{
    $form_array = (array) $form;
    $extra_array = (array) $extra;
    $candidates = [
        $form_array['fields'] ?? [],
        $form_array['form']['fields'] ?? [],
        $form_array['data'] ?? [],
        $form_array['values'] ?? [],
        $extra_array['fields'] ?? [],
        $extra_array['data'] ?? [],
        $extra_array['submission'] ?? [],
        $form,
        $_POST,
    ];

    foreach ($candidates as $candidate) {
        $candidate = (array) $candidate;

        if (!$candidate) {
            continue;
        }

        $is_field_list = false;

        foreach ($candidate as $item) {
            $item = (array) $item;

            if (array_key_exists('value', $item) && (isset($item['label']) || isset($item['name']) || isset($item['type']) || isset($item['advanced']))) {
                $is_field_list = true;
                break;
            }
        }

        if (!$is_field_list) {
            $flattened = vigilant_oxygen_form_mailer_flatten_fields($candidate);

            if ($flattened) {
                return $flattened;
            }

            continue;
        }

        $fields = [];

        foreach ($candidate as $field) {
            $field = (array) $field;
            $advanced = (array) ($field['advanced'] ?? []);
            $key = $advanced['id'] ?? ($field['id'] ?? ($field['name'] ?? ($field['label'] ?? ($field['type'] ?? 'field'))));
            $label = $field['label'] ?? ($advanced['label'] ?? vigilant_oxygen_form_mailer_pretty_label($key));
            $field_item = [
                'key' => (string) $key,
                'label' => vigilant_oxygen_form_mailer_pretty_label($label),
                'value' => $field['value'] ?? '',
            ];

            if (!vigilant_oxygen_form_mailer_is_public_field($field_item)) {
                continue;
            }

            $fields[] = $field_item;
        }

        if ($fields) {
            return $fields;
        }
    }

    return [];
}

function vigilant_oxygen_form_mailer_breakdance_submission($form, $extra, $settings)
{
    $fields = vigilant_oxygen_form_mailer_breakdance_fields($form, $extra);
    [$customer_email] = vigilant_oxygen_form_mailer_get_first_customer_email($form);

    if (!$customer_email) {
        $customer_email = vigilant_oxygen_form_mailer_find_field_value($fields, ['email']);
    }

    $customer_name = vigilant_oxygen_form_mailer_find_field_value($fields, ['name', 'first name']);

    return [
        'source' => 'breakdance',
        'source_id' => md5('breakdance|' . ($extra['postId'] ?? '') . '|' . ($extra['formId'] ?? '') . '|' . wp_json_encode($fields) . '|' . ($extra['referer'] ?? '')),
        'form_id' => (string) ($extra['formId'] ?? ''),
        'form_name' => $settings['form']['form_name'] ?? 'Breakdance Form',
        'source_url' => $extra['referer'] ?? '',
        'customer_email' => $customer_email,
        'customer_name' => $customer_name,
        'fields' => $fields,
        'ip' => $extra['ip'] ?? '',
        'user_agent' => $extra['userAgent'] ?? '',
    ];
}

function vigilant_oxygen_form_mailer_fluentform_submission($insert_id, $form_data, $form)
{
    $fields = vigilant_oxygen_form_mailer_flatten_fields($form_data);
    $customer_email = vigilant_oxygen_form_mailer_get_first_email_from_data($form_data);
    $customer_name = vigilant_oxygen_form_mailer_find_field_value($fields, ['name', 'first name']);
    $source_url = '';

    if (!empty($form_data['_wp_http_referer'])) {
        $source_url = (string) $form_data['_wp_http_referer'];
    }

    return [
        'source' => 'fluentform',
        'source_id' => (string) $insert_id,
        'form_id' => (string) ($form->id ?? ''),
        'form_name' => $form->title ?? 'Fluent Form',
        'source_url' => $source_url,
        'customer_email' => $customer_email,
        'customer_name' => $customer_name,
        'fields' => $fields,
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
    ];
}

function vigilant_oxygen_form_mailer_send_customer_receipt_email($customer_email, $submission = [])
{
    $plugin_settings = vigilant_oxygen_form_mailer_get_settings();

    if (empty($plugin_settings['send_customer_receipt'])) {
        return false;
    }

    $customer_email = sanitize_email($customer_email);
    $sent_key = strtolower($customer_email);

    if (!$customer_email || !is_email($customer_email) || !empty($GLOBALS['vigilant_oxygen_form_mailer_receipts_sent'][$sent_key])) {
        return false;
    }

    $bcc_emails = vigilant_oxygen_form_mailer_parse_emails($plugin_settings['bcc_emails']);

    $bcc_emails = array_values(array_unique(array_filter($bcc_emails, static function ($email) use ($customer_email) {
        return strtolower($email) !== strtolower($customer_email);
    })));

    $from_email = is_email($plugin_settings['from_email']) ? sanitize_email($plugin_settings['from_email']) : 'spillari@gmail.com';
    $headers = [
        sprintf('From: %s <%s>', sanitize_text_field($plugin_settings['from_name']), $from_email),
        'Content-Type: text/html; charset=UTF-8',
    ];

    foreach ($bcc_emails as $email) {
        $headers[] = 'Bcc: ' . $email;
    }

    $sent = wp_mail(
        $customer_email,
        sanitize_text_field($plugin_settings['customer_subject']),
        wpautop(wp_kses_post($plugin_settings['customer_message'])),
        $headers
    );

    if ($sent && $submission) {
        wp_mail(
            $customer_email,
            sanitize_text_field($plugin_settings['admin_subject']),
            vigilant_oxygen_form_mailer_build_admin_body($submission),
            [
                sprintf('From: %s <%s>', sanitize_text_field($plugin_settings['from_name']), $from_email),
                'Content-Type: text/plain; charset=UTF-8',
            ]
        );
    }

    if ($sent) {
        $GLOBALS['vigilant_oxygen_form_mailer_receipts_sent'][$sent_key] = true;
    }

    return $sent;
}

function vigilant_oxygen_form_mailer_send_customer_receipt($can_execute, $action, $extra, $form, $settings)
{
    if (!$can_execute || is_wp_error($can_execute)) {
        return $can_execute;
    }

    vigilant_oxygen_form_mailer_handle_submission(vigilant_oxygen_form_mailer_breakdance_submission($form, $extra, $settings));

    return $can_execute;
}
add_filter('breakdance_form_run_action_email', 'vigilant_oxygen_form_mailer_send_customer_receipt', 20, 5);
add_filter('breakdance_form_run_action_store_submission', 'vigilant_oxygen_form_mailer_send_customer_receipt', 20, 5);

function vigilant_oxygen_form_mailer_get_first_email_from_data($data)
{
    foreach ((array) $data as $value) {
        if (is_array($value) || is_object($value)) {
            $email = vigilant_oxygen_form_mailer_get_first_email_from_data((array) $value);

            if ($email) {
                return $email;
            }

            continue;
        }

        $email = sanitize_email((string) $value);

        if (is_email($email)) {
            return $email;
        }
    }

    return '';
}

function vigilant_oxygen_form_mailer_send_fluentform_customer_receipt($insert_id, $form_data, $form)
{
    vigilant_oxygen_form_mailer_handle_submission(vigilant_oxygen_form_mailer_fluentform_submission($insert_id, $form_data, $form));
}
add_action('fluentform/submission_inserted', 'vigilant_oxygen_form_mailer_send_fluentform_customer_receipt', 20, 3);

add_action('admin_init', function () {
    register_setting(
        'vigilant_oxygen_form_mailer',
        VIGILANT_OXYGEN_FORM_MAILER_OPTION,
        [
            'type' => 'array',
            'sanitize_callback' => function ($settings) {
                $settings = is_array($settings) ? $settings : [];

                return [
                    'to_email' => sanitize_email($settings['to_email'] ?? 'info@vigilant-inc.com'),
                    'bcc_emails' => implode(', ', vigilant_oxygen_form_mailer_parse_emails($settings['bcc_emails'] ?? '')),
                    'from_email' => sanitize_email($settings['from_email'] ?? 'spillari@gmail.com'),
                    'from_name' => sanitize_text_field($settings['from_name'] ?? 'Vigilant Technologies'),
                    'admin_subject' => sanitize_text_field($settings['admin_subject'] ?? 'Thanks for Your Interest in Vigilant'),
                    'send_customer_receipt' => !empty($settings['send_customer_receipt']) ? '1' : '',
                    'customer_subject' => sanitize_text_field($settings['customer_subject'] ?? 'Thank you — we’ve received your submission'),
                    'customer_message' => wp_kses_post($settings['customer_message'] ?? ''),
                ];
            },
            'default' => vigilant_oxygen_form_mailer_default_settings(),
        ]
    );
});

add_action('admin_menu', function () {
    add_options_page(
        'Vigilant Oxygen Form Mailer',
        'Vigilant Form Mailer',
        'manage_options',
        'vigilant-oxygen-form-mailer',
        'vigilant_oxygen_form_mailer_render_settings_page'
    );

    add_submenu_page(
        'tools.php',
        'Vigilant Form Submissions',
        'Vigilant Form Submissions',
        'manage_options',
        'vigilant-form-submissions',
        'vigilant_oxygen_form_mailer_render_submissions_page'
    );
});

add_action('admin_post_vigilant_delete_form_submission', function () {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to delete submissions.');
    }

    $submission_id = isset($_POST['submission_id']) ? absint($_POST['submission_id']) : 0;

    if (!$submission_id) {
        wp_safe_redirect(admin_url('tools.php?page=vigilant-form-submissions'));
        exit;
    }

    check_admin_referer('vigilant_delete_form_submission_' . $submission_id);

    global $wpdb;

    $wpdb->delete(vigilant_oxygen_form_mailer_table_name(), ['id' => $submission_id], ['%d']);

    $redirect_url = admin_url('tools.php?page=vigilant-form-submissions');

    if (!empty($_POST['paged'])) {
        $redirect_url = add_query_arg('paged', absint($_POST['paged']), $redirect_url);
    }

    wp_safe_redirect($redirect_url);
    exit;
});

function vigilant_oxygen_form_mailer_render_submissions_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;

    $table = vigilant_oxygen_form_mailer_table_name();
    $page = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
    $per_page = 25;
    $offset = ($page - 1) * $per_page;
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset));
    $total_pages = max(1, (int) ceil($total / $per_page));
    ?>
    <div class="wrap">
        <h1>Vigilant Form Submissions</h1>
        <p>All captured Breakdance/Oxygen and FluentForm submissions are stored here.</p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Form</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Fields</th>
                    <th>Source URL</th>
                    <th>Email Sent</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows) : ?>
                    <tr><td colspan="8">No submissions found.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n('M j, Y g:i a', strtotime($row->created_at))); ?></td>
                        <td>
                            <strong><?php echo esc_html($row->form_name ?: $row->source); ?></strong><br>
                            <small><?php echo esc_html($row->source); ?> #<?php echo esc_html($row->form_id); ?></small>
                        </td>
                        <td><?php echo esc_html($row->customer_name); ?></td>
                        <td><?php echo esc_html($row->customer_email); ?></td>
                        <td><pre style="white-space:pre-wrap;margin:0;max-width:420px;"><?php echo esc_html($row->fields_text); ?></pre></td>
                        <td>
                            <?php if ($row->source_url) : ?>
                                <a href="<?php echo esc_url($row->source_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($row->source_url); ?></a>
                            <?php endif; ?>
                        </td>
                        <td>
                            Admin: <?php echo $row->admin_email_sent ? 'Yes' : 'No'; ?><br>
                            Customer: <?php echo $row->customer_email_sent ? 'Yes' : 'No'; ?>
                        </td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete this submission?');">
                                <input type="hidden" name="action" value="vigilant_delete_form_submission">
                                <input type="hidden" name="submission_id" value="<?php echo esc_attr($row->id); ?>">
                                <input type="hidden" name="paged" value="<?php echo esc_attr($page); ?>">
                                <?php wp_nonce_field('vigilant_delete_form_submission_' . $row->id); ?>
                                <button type="submit" class="button-link-delete">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($total_pages > 1) : ?>
            <p>
                <?php if ($page > 1) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg('paged', $page - 1)); ?>">Previous</a>
                <?php endif; ?>
                <span>Page <?php echo esc_html($page); ?> of <?php echo esc_html($total_pages); ?></span>
                <?php if ($page < $total_pages) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg('paged', $page + 1)); ?>">Next</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
    <?php
}

function vigilant_oxygen_form_mailer_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = vigilant_oxygen_form_mailer_get_settings();
    ?>
    <div class="wrap">
        <h1>Vigilant Oxygen Form Mailer</h1>
        <form method="post" action="options.php">
            <?php settings_fields('vigilant_oxygen_form_mailer'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="vigilant_mail_to_email">Main recipient</label></th>
                    <td>
                        <input type="email" class="regular-text" id="vigilant_mail_to_email" name="<?php echo esc_attr(VIGILANT_OXYGEN_FORM_MAILER_OPTION); ?>[to_email]" value="<?php echo esc_attr($settings['to_email']); ?>">
                        <p class="description">Admin notifications for all captured forms are sent here.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="vigilant_mail_bcc_emails">BCC recipients</label></th>
                    <td>
                        <textarea class="large-text code" rows="4" id="vigilant_mail_bcc_emails" name="<?php echo esc_attr(VIGILANT_OXYGEN_FORM_MAILER_OPTION); ?>[bcc_emails]"><?php echo esc_textarea($settings['bcc_emails']); ?></textarea>
                        <p class="description">Separate multiple email addresses with commas, semicolons, or new lines.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="vigilant_mail_from_email">Fallback from email</label></th>
                    <td>
                        <input type="email" class="regular-text" id="vigilant_mail_from_email" name="<?php echo esc_attr(VIGILANT_OXYGEN_FORM_MAILER_OPTION); ?>[from_email]" value="<?php echo esc_attr($settings['from_email']); ?>">
                        <p class="description">Used only when an Oxygen form email has no valid From header.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="vigilant_mail_from_name">Fallback from name</label></th>
                    <td>
                        <input type="text" class="regular-text" id="vigilant_mail_from_name" name="<?php echo esc_attr(VIGILANT_OXYGEN_FORM_MAILER_OPTION); ?>[from_name]" value="<?php echo esc_attr($settings['from_name']); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="vigilant_mail_admin_subject">Admin subject</label></th>
                    <td>
                        <input type="text" class="regular-text" id="vigilant_mail_admin_subject" name="<?php echo esc_attr(VIGILANT_OXYGEN_FORM_MAILER_OPTION); ?>[admin_subject]" value="<?php echo esc_attr($settings['admin_subject']); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Customer receipt</th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(VIGILANT_OXYGEN_FORM_MAILER_OPTION); ?>[send_customer_receipt]" value="1" <?php checked($settings['send_customer_receipt'], '1'); ?>>
                            Send a confirmation email to the first submitted email address if the form has no existing customer email action.
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="vigilant_mail_customer_subject">Customer subject</label></th>
                    <td>
                        <input type="text" class="regular-text" id="vigilant_mail_customer_subject" name="<?php echo esc_attr(VIGILANT_OXYGEN_FORM_MAILER_OPTION); ?>[customer_subject]" value="<?php echo esc_attr($settings['customer_subject']); ?>">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="vigilant_mail_customer_message">Customer message</label></th>
                    <td>
                        <textarea class="large-text" rows="5" id="vigilant_mail_customer_message" name="<?php echo esc_attr(VIGILANT_OXYGEN_FORM_MAILER_OPTION); ?>[customer_message]"><?php echo esc_textarea($settings['customer_message']); ?></textarea>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}
