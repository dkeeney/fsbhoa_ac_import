<?php
/**
 * Handles the REST API endpoint for triggering and monitoring the CSV import.
 * Version 2: Includes locking and status reporting.
 */

if (!defined('WPINC')) {
    die;
}

// Ensure the main import class is available
require_once plugin_dir_path(__FILE__) . 'class-fsbhoa-import-v2.php';

class Fsbhoa_Import_REST_API {

    private $namespace = 'fsbhoa/v1';
    private const LOCK_OPTION_NAME = 'fsbhoa_import_lock';
    private const STALE_LOCK_SECONDS = 3600; // 1 hour

    /**
     * Registers the REST API routes.
     */
    public function register_routes() {
        register_rest_route($this->namespace, '/import/run', [
            'methods'             => 'POST',
            'callback'            => [$this, 'run_import_callback'],
            'permission_callback' => [$this, 'permission_check'],
        ]);

        register_rest_route($this->namespace, '/import/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_import_status_callback'],
            'permission_callback' => [$this, 'permission_check'],
        ]);
    }

    /**
     * Security check for the API endpoint.
     */
    public function permission_check(WP_REST_Request $request) {
        $provided_key = $request->get_header('X-API-KEY');
        if (empty($provided_key)) {
            return new WP_Error('rest_forbidden', 'API Key is missing.', ['status' => 401]);
        }
        $stored_key = get_option('fsbhoa_ac_api_key', ''); 
        if (empty($stored_key) || !hash_equals($stored_key, $provided_key)) {
            return new WP_Error('rest_forbidden', 'Invalid API Key.', ['status' => 403]);
        }
        return true;
    }

    /**
     * The callback function that executes the import with locking.
     */
    public function run_import_callback(WP_REST_Request $request) {
        $params = $request->get_json_params();
        $file_path = isset($params['file_path']) ? sanitize_text_field($params['file_path']) : '';
        // NEW: Check for the dry_run flag in the JSON payload
        $is_dry_run = isset($params['dry_run']) && $params['dry_run'] === true;

        // If it's a dry run, we skip all the locking logic and just run the process.
        if ($is_dry_run) {
            try {
                if (empty($file_path) || !file_exists($file_path) || !is_readable($file_path)) {
                    throw new Exception('File path is missing, does not exist, or is not readable.', 400);
                }
                $importer = new Fsbhoa_Import_V2();
                // Call the importer with the dry run flag set to true
                $feedback = $importer->process_csv_file($file_path, true);
                return new WP_REST_Response($feedback, 200);
            } catch (Exception $e) {
                $status_code = ($e->getCode() > 0) ? $e->getCode() : 500;
                return new WP_Error('import_failed', 'Dry run failed: ' . $e->getMessage(), ['status' => $status_code]);
            }
        }

        // --- All code below is for a "live" run (not a dry run) ---
        // --- LOCKING LOGIC START ---
        $lock_data = get_option(self::LOCK_OPTION_NAME, []);
        if (isset($lock_data['status']) && $lock_data['status'] === 'running') {
            $time_since_lock = time() - ($lock_data['start_time'] ?? time());
            if ($time_since_lock < self::STALE_LOCK_SECONDS) {
                return new WP_Error(
                    'import_locked',
                    sprintf('An import is already in progress. Started %d seconds ago.', $time_since_lock),
                    ['status' => 429] // 429 Too Many Requests
                );
            }
        }

        // Set the lock
        $new_lock_data = [
            'status'     => 'running',
            'start_time' => time(),
            'summary'    => 'Processing...',
            'end_time'   => null,
        ];
        update_option(self::LOCK_OPTION_NAME, $new_lock_data, false); // false = don't autoload
        // --- LOCKING LOGIC END ---

        $feedback = [];
        try {
            $params = $request->get_json_params();
            $file_path = isset($params['file_path']) ? sanitize_text_field($params['file_path']) : '';

            if (empty($file_path) || !file_exists($file_path) || !is_readable($file_path)) {
                throw new Exception('File path is missing, does not exist, or is not readable.', 400);
            }

            $importer = new Fsbhoa_Import_V2();
            $feedback = $importer->process_csv_file($file_path);
            
            // On success, update lock with final status
            $final_lock_data = [
                'status'     => 'completed',
                'start_time' => $new_lock_data['start_time'],
                'end_time'   => time(),
                'summary'    => $feedback,
            ];
            update_option(self::LOCK_OPTION_NAME, $final_lock_data, false);
            
            return new WP_REST_Response($feedback, 200);

        } catch (Exception $e) {
            // On failure, update lock with error status
            $error_lock_data = [
                'status'     => 'failed',
                'start_time' => $new_lock_data['start_time'],
                'end_time'   => time(),
                'summary'    => 'A critical error occurred: ' . $e->getMessage(),
            ];
            update_option(self::LOCK_OPTION_NAME, $error_lock_data, false);
            
            $status_code = ($e->getCode() > 0) ? $e->getCode() : 500;
            return new WP_Error('import_failed', 'Error during import: ' . $e->getMessage(), ['status' => $status_code]);
        }
    }

    /**
     * The callback function to get the status of the last import.
     */
    public function get_import_status_callback(WP_REST_Request $request) {
        $lock_data = get_option(self::LOCK_OPTION_NAME, []);
        
        if (empty($lock_data)) {
            $response = [
                'status' => 'idle',
                'message' => 'No import has been run yet.'
            ];
        } else {
            $response = $lock_data;
            if (isset($response['start_time'])) {
                $response['start_time_human'] = date('Y-m-d H:i:s T', $response['start_time']);
            }
            if (isset($response['end_time'])) {
                $response['end_time_human'] = date('Y-m-d H:i:s T', $response['end_time']);
            }
        }
        
        return new WP_REST_Response($response, 200);
    }
}

