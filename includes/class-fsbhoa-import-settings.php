<?php
/**
 * Import settings, shown as an "Import Settings" section on core's General Settings page.
 * Core's AJAX saver stores every field on that page, so no save handler is needed here.
 */

if (!defined('WPINC')) {
    die;
}

class Fsbhoa_Import_Settings {

    const ENVIRONMENTS        = ['testbed', 'production'];
    const FOLDER_OPTION       = 'fsbhoa_import_folder';
    const FOLDER_DEFAULT      = '/mnt/shared/AccessControl';
    const CORE_GENERAL_PAGE   = 'fsbhoa_ac_main_menu';
    const CORE_OPTION_GROUP   = 'fsbhoa_general_options';

    public function __construct() {
        // Priority 20 so this section renders after core's own General Settings sections
        add_action('admin_init', [$this, 'settings_api_init'], 20);
    }

    public function settings_api_init() {
        add_settings_section('fsbhoa_import_settings_section', 'Import Settings', null, self::CORE_GENERAL_PAGE);

        add_settings_field(
            'fsbhoa_import_folder_field',
            'Import Folder',
            [$this, 'render_folder_field'],
            self::CORE_GENERAL_PAGE,
            'fsbhoa_import_settings_section'
        );
        register_setting(self::CORE_OPTION_GROUP, self::FOLDER_OPTION, 'sanitize_text_field');
    }

    public function render_folder_field() {
        $value = get_option(self::FOLDER_OPTION, self::FOLDER_DEFAULT);
        $env   = self::environment();
        ?>
        <input type="text" name="<?php echo esc_attr(self::FOLDER_OPTION); ?>" id="<?php echo esc_attr(self::FOLDER_OPTION); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description">
            Folder for import files: the AutoHotKey script places the CSV here and the import writes the
            contact mismatch report here. The environment name is appended,
            <?php if ($env) : ?>
                so this server uses <code><?php echo esc_html(self::environment_dir()); ?></code>.
            <?php else : ?>
                but <code>FSBHOA_AC_ENVIRONMENT</code> is not set in wp-config.php, so imports via the REST API and the report are disabled.
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * Returns this server's environment from FSBHOA_AC_ENVIRONMENT in wp-config.php,
     * or '' if it is missing or not one of ENVIRONMENTS.
     */
    public static function environment() {
        $env = defined('FSBHOA_AC_ENVIRONMENT') ? strtolower(trim((string) FSBHOA_AC_ENVIRONMENT)) : '';
        return in_array($env, self::ENVIRONMENTS, true) ? $env : '';
    }

    /**
     * Returns the import folder for this environment (with trailing slash), or '' if the
     * environment is not set, so callers fail closed.
     */
    public static function environment_dir() {
        $env = self::environment();
        if ($env === '') {
            return '';
        }
        $base = rtrim(get_option(self::FOLDER_OPTION, self::FOLDER_DEFAULT), '/');
        return $base . '/' . $env . '/';
    }
}
