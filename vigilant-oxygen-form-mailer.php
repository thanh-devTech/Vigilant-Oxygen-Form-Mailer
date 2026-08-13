<?php
/**
 * Plugin Name: Vigilant Oxygen Form Mailer
 * Description: Ensures Oxygen/Breakdance form emails notify Vigilant recipients and BCC list without changing Oxygen core files.
 * Version: 1.0.0
 * Author: CI Web Studio
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VIGILANT_OXYGEN_FORM_MAILER_OPTION', 'vigilant_oxygen_form_mailer_settings');

function vigilant_oxygen_form_mailer_default_settings()
{
    return [
        'to_email' => 'info@vigilant-inc.com',
        'bcc_emails' => '',
        'from_email' => 'spillari@gmail.com',
        'from_name' => 'Vigilant Technologies',
        'send_customer_receipt' => '1',
        'customer_subject' => 'Thank you — we’ve received your submission',
        'customer_message' => "Thank you for reaching out to Vigilant 360. Your submission has been received and is currently being reviewed by our team.\n\nWe’ll make sure the right professional follows up with you as soon as possible.\n\nIf you need assistance in the meantime or would like to talk to us immediately, please contact me directly at 313-715-6988 or swebster@vigilant-inc.com.\n\nBest regards,\nSarah Webster\nChief Marketing Officer\nVigilant 360",
    ];
}

register_activation_hook(__FILE__, function () {
    if (!get_option(VIGILANT_OXYGEN_FORM_MAILER_OPTION)) {
        add_option(VIGILANT_OXYGEN_FORM_MAILER_OPTION, vigilant_oxygen_form_mailer_default_settings());
    }
});

add_action('plugins_loaded', function () {
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

    if (is_email($main_to) && !in_array($main_to, $to_emails, true)) {
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

function vigilant_oxygen_form_mailer_send_customer_receipt_email($customer_email)
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
    $main_to = sanitize_email($plugin_settings['to_email']);

    if (is_email($main_to)) {
        $bcc_emails[] = $main_to;
    }

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

    [$customer_email] = vigilant_oxygen_form_mailer_get_first_customer_email($form);

    if (!$customer_email) {
        return $can_execute;
    }

    vigilant_oxygen_form_mailer_send_customer_receipt_email($customer_email);

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
    $customer_email = vigilant_oxygen_form_mailer_get_first_email_from_data($form_data);

    if (!$customer_email) {
        return;
    }

    $transient_key = 'vigilant_form_receipt_' . md5('fluentform|' . $insert_id . '|' . strtolower($customer_email));

    if (get_transient($transient_key)) {
        return;
    }

    if (vigilant_oxygen_form_mailer_send_customer_receipt_email($customer_email)) {
        set_transient($transient_key, '1', DAY_IN_SECONDS);
    }
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
});

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
                        <p class="description">Oxygen form emails will always include this recipient.</p>
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
