<?php
/**
 * Plugin Name: Grace Parish Form
 * Description: Parish registration form for CCC Worldwide. Place it on any page with the [grace_parish_form] shortcode; submissions appear under "Grace Parishes" in the admin menu.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Text Domain: grace-parish-form
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Grace_Parish_Form
{
    const VERSION    = '1.0.0';
    const TABLE      = 'grace_parishes';
    const MAX_PHONES = 5;

    /** Single-value text fields: name => [label, max length]. Every field is optional. */
    const FIELDS = [
        'parish_name'   => ['Parish name', 200],
        'shepherd_name' => ['Name of the shepherd', 200],
        'email'         => ['Email address', 254],
        'city'          => ['City', 120],
        'county'        => ['County', 120],
        'state'         => ['State / Province', 120],
        'country'       => ['Country', 120],
    ];

    /** Errors and submitted values from a failed POST, re-shown by the shortcode. */
    private static $errors = [];
    private static $values = [];

    public static function init()
    {
        register_activation_hook(__FILE__, [__CLASS__, 'install']);
        add_action('plugins_loaded', [__CLASS__, 'maybe_upgrade']);
        add_action('init', [__CLASS__, 'handle_submit']);
        add_shortcode('grace_parish_form', [__CLASS__, 'render_form']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_post_grace_parish_export', [__CLASS__, 'export_csv']);
    }

    private static function table()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    // ------------------------------------------------------------------ setup

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // phones holds a JSON array because a parish can give several numbers.
        dbDelta('CREATE TABLE ' . self::table() . ' (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            parish_name varchar(200) NULL,
            shepherd_name varchar(200) NULL,
            phones text NULL,
            email varchar(254) NULL,
            city varchar(120) NULL,
            county varchar(120) NULL,
            state varchar(120) NULL,
            country varchar(120) NULL,
            submitter_ip varchar(45) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY country_state (country(60), state(60)),
            KEY created_at (created_at)
        ) ' . $wpdb->get_charset_collate() . ';');

        update_option('grace_parish_form_version', self::VERSION);
    }

    public static function maybe_upgrade()
    {
        if (get_option('grace_parish_form_version') !== self::VERSION) {
            self::install();
        }
    }

    // ------------------------------------------------------------- submission

    public static function handle_submit()
    {
        if (empty($_POST['grace_parish_form'])) {
            return;
        }
        // No nonce on purpose: this is an anonymous public form (nothing for CSRF
        // to abuse), and cached pages would serve stale nonces that reject real
        // visitors. The honeypot below handles basic bot spam.

        // Back to the page the form is on (it posts to itself).
        $redirect = remove_query_arg('grace_submitted', isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : home_url('/'));

        // Honeypot: people never see this field; bots fill it. Pretend success.
        if (!empty($_POST['gpf_website'])) {
            wp_safe_redirect(add_query_arg('grace_submitted', '1', $redirect));
            exit;
        }

        $data = [];
        foreach (self::FIELDS as $name => list($label, $max)) {
            $value = isset($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : '';
            if (mb_strlen($value) > $max) {
                self::$errors[$name] = sprintf('%s must be %d characters or fewer.', $label, $max);
            }
            $data[$name] = $value;
        }

        $phones = [];
        $raw = isset($_POST['phones']) && is_array($_POST['phones']) ? wp_unslash($_POST['phones']) : [];
        foreach (array_slice($raw, 0, self::MAX_PHONES) as $phone) {
            $phone = sanitize_text_field((string) $phone);
            if ($phone === '') {
                continue;
            }
            if (!self::valid_phone($phone)) {
                self::$errors['phones'] = 'Please check the phone number(s): use digits, with an optional + and spaces or dashes.';
            }
            $phones[] = $phone;
        }

        if ($data['email'] !== '' && !is_email($data['email'])) {
            self::$errors['email'] = 'Please check the email address.';
        }

        // Nothing is required, but a completely blank form isn't a submission.
        if (!$phones && !array_filter($data, 'strlen')) {
            self::$errors['_form'] = 'Please fill in at least one field before submitting.';
        }

        if (self::$errors) {
            self::$values = $data + ['phones' => $phones];
            return;
        }

        global $wpdb;
        $row = array_map(function ($v) {
            return $v === '' ? null : $v;
        }, $data);
        $row['phones']       = $phones ? wp_json_encode($phones) : null;
        $row['submitter_ip'] = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null;
        $row['created_at']   = current_time('mysql');

        if ($wpdb->insert(self::table(), $row) === false) {
            self::$values = $data + ['phones' => $phones];
            self::$errors['_form'] = 'Sorry, something went wrong saving your details. Please try again.';
            return;
        }

        wp_safe_redirect(add_query_arg('grace_submitted', '1', $redirect));
        exit;
    }

    /** Digits with an optional leading + and spaces, dashes, dots or brackets; 7–15 digits. */
    private static function valid_phone($phone)
    {
        $digits = strlen(preg_replace('/\D/', '', $phone));
        return (bool) preg_match('/^\+?[\d\s().-]+$/', $phone) && $digits >= 7 && $digits <= 15;
    }

    // ------------------------------------------------------------------- form

    public static function render_form()
    {
        if (isset($_GET['grace_submitted'])) {
            return '<div class="gpf"><p class="gpf-notice gpf-ok" role="status">'
                . 'Thank you. Your parish details have been received.</p></div>'
                . self::styles();
        }

        $v      = self::$values;
        $e      = self::$errors;
        $phones = !empty($v['phones']) ? $v['phones'] : [''];

        $text = function ($name, $autocomplete = '', $type = 'text', $placeholder = '') use ($v, $e) {
            list($label, $max) = self::FIELDS[$name];
            $id = 'gpf-' . $name;
            $html  = '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
            $html .= '<input id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '"'
                . ' maxlength="' . (int) $max . '" value="' . esc_attr(isset($v[$name]) ? $v[$name] : '') . '"'
                . ($autocomplete ? ' autocomplete="' . esc_attr($autocomplete) . '"' : '')
                . ($placeholder ? ' placeholder="' . esc_attr($placeholder) . '"' : '')
                . (isset($e[$name]) ? ' aria-invalid="true"' : '') . '>';
            if (isset($e[$name])) {
                $html .= '<p class="gpf-error">' . esc_html($e[$name]) . '</p>';
            }
            return '<div class="gpf-field">' . $html . '</div>';
        };

        ob_start();
        ?>
        <div class="gpf">
          <form method="post" action="" novalidate>
            <?php if (isset($e['_form'])) : ?>
              <p class="gpf-notice gpf-fail" role="alert"><?php echo esc_html($e['_form']); ?></p>
            <?php endif; ?>

            <?php echo $text('parish_name', 'organization'); ?>
            <?php echo $text('shepherd_name', 'name'); ?>

            <div class="gpf-field">
              <label for="gpf-phone-0">Phone number(s)</label>
              <div class="gpf-phones">
                <?php foreach ($phones as $i => $phone) : ?>
                  <div class="gpf-phone">
                    <input id="gpf-phone-<?php echo (int) $i; ?>" name="phones[]" type="tel" maxlength="30"
                           autocomplete="tel" placeholder="+234 803 123 4567"
                           <?php echo $i ? 'aria-label="Another phone number"' : ''; ?>
                           value="<?php echo esc_attr($phone); ?>"
                           <?php echo isset($e['phones']) ? 'aria-invalid="true"' : ''; ?>>
                    <button type="button" class="gpf-remove" aria-label="Remove this phone number"<?php echo $i ? '' : ' hidden'; ?>>&times;</button>
                  </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="gpf-add" hidden>+ Add another phone number</button>
              <?php if (isset($e['phones'])) : ?>
                <p class="gpf-error"><?php echo esc_html($e['phones']); ?></p>
              <?php endif; ?>
            </div>

            <?php echo $text('email', 'email', 'email', 'name@example.com'); ?>

            <div class="gpf-row">
              <?php echo $text('city', 'address-level2'); ?>
              <?php echo $text('county'); ?>
            </div>
            <div class="gpf-row">
              <?php echo $text('state', 'address-level1'); ?>
              <?php echo $text('country', 'country-name'); ?>
            </div>

            <div class="gpf-hp" aria-hidden="true">
              <label for="gpf-website">Website</label>
              <input id="gpf-website" name="gpf_website" tabindex="-1" autocomplete="off">
            </div>

            <input type="hidden" name="grace_parish_form" value="1">
            <button type="submit" class="gpf-submit">Submit</button>
          </form>
        </div>
        <script>
        (function () {
          var root = document.currentScript.previousElementSibling;
          var list = root.querySelector('.gpf-phones');
          var add = root.querySelector('.gpf-add');
          var max = <?php echo (int) self::MAX_PHONES; ?>;
          function sync() {
            var rows = list.querySelectorAll('.gpf-phone');
            add.hidden = rows.length >= max;
            rows.forEach(function (row) {
              row.querySelector('.gpf-remove').hidden = rows.length === 1;
            });
          }
          add.addEventListener('click', function () {
            var row = list.querySelector('.gpf-phone').cloneNode(true);
            var input = row.querySelector('input');
            input.value = '';
            input.removeAttribute('id');
            input.removeAttribute('aria-invalid');
            input.setAttribute('aria-label', 'Another phone number');
            list.appendChild(row);
            sync();
            input.focus();
          });
          list.addEventListener('click', function (e) {
            if (!e.target.classList.contains('gpf-remove')) return;
            e.target.parentNode.remove();
            list.querySelector('.gpf-phone input').id = 'gpf-phone-0';
            sync();
          });
          sync();
        })();
        </script>
        <?php
        return ob_get_clean() . self::styles();
    }

    /** Scoped styles; fonts and colours inherit from the theme where possible. */
    private static function styles()
    {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;
        return '<style>
.gpf{max-width:560px;margin:0 auto;--gpf-border:rgba(0,0,0,.2);--gpf-accent:#1f4e8c;--gpf-error:#b42318;--gpf-ok:#1a7f37}
.gpf *{box-sizing:border-box}
.gpf-field{margin:0 0 18px}
.gpf label{display:block;font-weight:600;margin:0 0 6px}
.gpf input{width:100%;padding:10px 12px;font:inherit;color:inherit;background:#fff;border:1px solid var(--gpf-border);border-radius:8px}
.gpf input:focus{outline:2px solid var(--gpf-accent);outline-offset:1px}
.gpf input[aria-invalid="true"]{border-color:var(--gpf-error)}
.gpf-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media (max-width:480px){.gpf-row{grid-template-columns:1fr}}
.gpf-phone{display:flex;gap:8px;margin:0 0 8px}
.gpf-phone input{flex:1}
.gpf-remove{flex:none;width:42px;font-size:20px;line-height:1;background:none;border:1px solid var(--gpf-border);border-radius:8px;cursor:pointer;color:inherit}
.gpf-add{background:none;border:0;padding:4px 0;font:inherit;font-weight:600;color:var(--gpf-accent);cursor:pointer}
.gpf-submit{width:100%;padding:12px;font:inherit;font-weight:600;color:#fff;background:var(--gpf-accent);border:0;border-radius:8px;cursor:pointer}
.gpf-error{color:var(--gpf-error);font-size:.875em;margin:4px 0 0}
.gpf-notice{padding:12px 14px;border-radius:8px;font-weight:600;margin:0 0 18px}
.gpf-ok{color:var(--gpf-ok);border:1px solid var(--gpf-ok)}
.gpf-fail{color:var(--gpf-error);border:1px solid var(--gpf-error)}
.gpf-hp{position:absolute;left:-9999px}
</style>';
    }

    // ------------------------------------------------------------------ admin

    public static function admin_menu()
    {
        add_menu_page('Grace Parishes', 'Grace Parishes', 'manage_options', 'grace-parishes',
            [__CLASS__, 'admin_page'], 'dashicons-groups', 26);
    }

    public static function admin_page()
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC LIMIT 500', ARRAY_A);
        $export = wp_nonce_url(admin_url('admin-post.php?action=grace_parish_export'), 'grace_parish_export');
        ?>
        <div class="wrap">
          <h1 class="wp-heading-inline">Grace Parishes</h1>
          <a href="<?php echo esc_url($export); ?>" class="page-title-action">Download CSV</a>
          <p>Add the form to any page with the shortcode <code>[grace_parish_form]</code>. Showing the latest 500 submissions; the CSV has all of them.</p>
          <table class="widefat striped">
            <thead><tr>
              <th>Date</th><th>Parish</th><th>Shepherd</th><th>Phone(s)</th><th>Email</th>
              <th>City</th><th>County</th><th>State</th><th>Country</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows) : ?>
              <tr><td colspan="9">No submissions yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r) : ?>
              <tr>
                <td><?php echo esc_html($r['created_at']); ?></td>
                <td><?php echo esc_html($r['parish_name']); ?></td>
                <td><?php echo esc_html($r['shepherd_name']); ?></td>
                <td><?php echo esc_html(self::phones_text($r['phones'])); ?></td>
                <td><?php echo $r['email'] ? '<a href="mailto:' . esc_attr($r['email']) . '">' . esc_html($r['email']) . '</a>' : ''; ?></td>
                <td><?php echo esc_html($r['city']); ?></td>
                <td><?php echo esc_html($r['county']); ?></td>
                <td><?php echo esc_html($r['state']); ?></td>
                <td><?php echo esc_html($r['country']); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php
    }

    public static function export_csv()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Not allowed.');
        }
        check_admin_referer('grace_parish_export');

        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC', ARRAY_A);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="grace-parishes-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8 names correctly
        fputcsv($out, ['Date', 'Parish', 'Shepherd', 'Phone(s)', 'Email', 'City', 'County', 'State', 'Country']);
        foreach ($rows as $r) {
            fputcsv($out, array_map([__CLASS__, 'csv_safe'], [
                $r['created_at'], $r['parish_name'], $r['shepherd_name'], self::phones_text($r['phones']),
                $r['email'], $r['city'], $r['county'], $r['state'], $r['country'],
            ]));
        }
        exit;
    }

    private static function phones_text($json)
    {
        $list = $json ? json_decode($json, true) : [];
        return is_array($list) ? implode(', ', $list) : '';
    }

    /** Stop spreadsheet apps from treating submitted text as a formula. */
    private static function csv_safe($value)
    {
        $value = (string) $value;
        if (preg_match('/^[+\d\s().,-]+$/', $value)) {
            return $value; // phone numbers and dates: no letters, so no formula
        }
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }
}

Grace_Parish_Form::init();
