<?php
/**
 * Adds import checks to core's Diagnostics test suite (filter 'fsbhoa_regression_test_steps').
 * The dry run calls the import's own REST endpoint with its API key, the way the AutoHotKey script does.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Fsbhoa_Import_Regression_Test {

    // A made-up address, so the dry run sees a new property with no residents:
    // nothing is counted as archived, and no contact mismatch report is written.
    const TEST_ADDRESS = '9999 Test Way';

    public function __construct() {
        add_filter( 'fsbhoa_regression_test_steps', array( $this, 'add_steps' ) );
    }

    public function add_steps( $steps ) {
        $steps[] = [ 'id' => 'import_folder',  'label' => 'Import: checking the environment import folder...',
                     'callback' => [ $this, 'check_folder' ] ];
        $steps[] = [ 'id' => 'import_dry_run', 'label' => 'Import: running a dry-run import of a sample CSV via REST API...',
                     'callback' => [ $this, 'run_dry_run' ] ];
        return $steps;
    }

    /**
     * The import folder must be set for this environment and writable, or the dry run can't place its CSV.
     */
    public function check_folder() {
        $dir = Fsbhoa_Import_Settings::environment_dir();
        if ( $dir === '' ) {
            return new WP_Error( 'no_environment', 'FSBHOA_AC_ENVIRONMENT is not set in wp-config.php (expected one of: ' . implode( ', ', Fsbhoa_Import_Settings::ENVIRONMENTS ) . '), so REST imports are disabled.' );
        }
        if ( ! is_dir( $dir ) ) {
            return new WP_Error( 'no_folder', 'Import folder ' . $dir . ' does not exist. Check the NAS mount and the Import Settings folder.' );
        }
        if ( ! is_writable( $dir ) ) {
            return new WP_Error( 'folder_not_writable', 'Import folder ' . $dir . ' is not writable by the web server.' );
        }
        return 'Import folder ' . $dir . ' is ready.';
    }

    /**
     * Writes a one-row CSV (an owner and a tenant) into the import folder, imports it as a dry run,
     * deletes it, and checks the counts in the report.
     */
    public function run_dry_run() {
        $dir = Fsbhoa_Import_Settings::environment_dir();
        if ( $dir === '' ) {
            return new WP_Error( 'no_environment', 'FSBHOA_AC_ENVIRONMENT is not set in wp-config.php, so REST imports are disabled.' );
        }
        $api_key = get_option( 'fsbhoa_ac_api_key', '' );
        if ( empty( $api_key ) ) {
            return new WP_Error( 'no_key', 'Import API Key (fsbhoa_ac_api_key) is not configured.' );
        }

        $file_path = $dir . 'diagnostics_test_import_' . wp_generate_password( 8, false ) . '.csv';
        $csv = "Property Address,First Name,Last Name,Tenant Name(s),Tenant Email(s)\n"
             . '"' . self::TEST_ADDRESS . "\",Test,Import,Diag Tenant,\n";
        if ( file_put_contents( $file_path, $csv ) === false ) {
            return new WP_Error( 'write_failed', 'Could not write the sample CSV to ' . $file_path . '.' );
        }

        try {
            $response = wp_remote_post( get_site_url() . '/wp-json/fsbhoa/v1/import/run', [
                'headers' => [ 'X-API-KEY' => $api_key, 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'file_path' => $file_path, 'dry_run' => true ] ),
                'timeout' => 30,
            ] );
        } finally {
            @unlink( $file_path );
        }

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'request_failed', 'Request to /import/run failed: ' . $response->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== $code ) {
            $message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : wp_remote_retrieve_body( $response );
            return new WP_Error( 'bad_status', '/import/run returned HTTP ' . $code . ': ' . $message );
        }
        if ( ! is_array( $data ) || ! isset( $data['messages'] ) || ! is_array( $data['messages'] ) ) {
            return new WP_Error( 'bad_report', 'The dry run response has no messages array.' );
        }
        if ( ( $data['type'] ?? '' ) !== 'success' ) {
            return new WP_Error( 'import_errors', 'The dry run reported problems: ' . implode( ' | ', $data['messages'] ) );
        }

        // One row with an owner and a tenant: both are new, and no one is archived.
        $report   = implode( "\n", $data['messages'] );
        $expected = [ 'Processed' => 1, 'Cardholders Created' => 2, 'Cardholders Archived' => 0 ];
        foreach ( $expected as $label => $count ) {
            $pattern = ( $label === 'Processed' ) ? '/Processed (\d+) rows/' : '/' . $label . ':\s+(\d+)/';
            if ( ! preg_match( $pattern, $report, $m ) || (int) $m[1] !== $count ) {
                return new WP_Error( 'unexpected_counts', sprintf( 'Expected "%s" to be %d. Dry run report: %s', $label, $count, implode( ' | ', $data['messages'] ) ) );
            }
        }
        return 'Dry-run import of the sample CSV processed 1 row and found 2 new cardholders.';
    }
}
