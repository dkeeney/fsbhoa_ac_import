<?php
/**
 * Plugin Name: FSBHOA Access Control - Import
 * Description: Subordinate plugin to handle CSV imports from the PMP property management system.
 * Version: 2.1.0
 * Author: FSBHOA IT Committee
 *
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    die;
}

// Hook late enough to ensure the core plugin has loaded its functions
add_action('plugins_loaded', 'fsbhoa_import_module_init');

function fsbhoa_import_module_init() {
    // DEPENDENCY CHECK: Ensure the core plugin is active by checking for a known core function
    if (!function_exists('fsbhoa_archive_and_delete_cardholder')) {
        add_action('admin_notices', 'fsbhoa_import_missing_core_notice');
        return;
    }

    // Load the classes
    require_once plugin_dir_path(__FILE__) . 'includes/class-fsbhoa-import-v2.php';
    require_once plugin_dir_path(__FILE__) . 'includes/class-fsbhoa-import-rest-api.php';

    // Register Shortcode
    add_shortcode('fsbhoa_import_form', 'fsbhoa_render_import_shortcode_v2');

    // Initialize REST API
    $import_api = new Fsbhoa_Import_REST_API();
    add_action('rest_api_init', [$import_api, 'register_routes']);
}

/**
 * Renders the HTML for the import form by calling the class.
 */
function fsbhoa_render_import_shortcode_v2() {
    $importer = new Fsbhoa_Import_V2();
    return $importer->render_shortcode_page();
}

/**
 * Admin notice if the core plugin is missing.
 */
function fsbhoa_import_missing_core_notice() {
    echo '<div class="notice notice-error"><p><strong>FSBHOA Import Module:</strong> The FSBHOA Access Control Core plugin is not active. The import module has been disabled.</p></div>';
}

/**
 * One-time database migration to standardize addresses and merge duplicates.
 * Locked behind a WP option flag so it only executes once per environment.
 * After it has been ran in production, remove this code.
 */
function fsbhoa_run_one_time_address_dedupe_and_cleanup() {
    // 1. Check if the migration has already run. If yes, silently exit.
    if ( get_option( 'fsbhoa_ac_address_standardization_complete' ) ) {
        return;
    }

    global $wpdb;

    // 2. Sort so 'manual' properties are processed first and become the "surviving" master records
    $query = "SELECT * FROM ac_property ORDER BY CASE WHEN origin = 'manual' THEN 1 ELSE 2 END, property_id ASC";
    $properties = $wpdb->get_results($query);

    $seen_addresses = []; // Maps 'house_number street_name' to the surviving property_id
    $updated_count = 0;
    $merged_count = 0;

    foreach ($properties as $prop) {
        $standardized = fsbhoa_standardize_address($prop->street_address);

        if (preg_match('/^([0-9]+[A-Z]?)\s+(.*)/', $standardized, $matches)) {
            $house_number = trim($matches[1]);
            $street_name = trim($matches[2]);
            $unique_key = $house_number . ' ' . $street_name;

            if (isset($seen_addresses[$unique_key])) {
                // WE FOUND A DUPLICATE
                $surviving_id = $seen_addresses[$unique_key];
                $duplicate_id = $prop->property_id;

                // Move all cardholders to the surviving property
                $wpdb->update(
                    'ac_cardholders',
                    ['property_id' => $surviving_id],
                    ['property_id' => $duplicate_id],
                    ['%d'],
                    ['%d']
                );

                // Delete the duplicate property
                $wpdb->delete(
                    'ac_property',
                    ['property_id' => $duplicate_id],
                    ['%d']
                );

                $merged_count++;

            } else {
                // FIRST TIME SEEING THIS ADDRESS - Make it the master
                $seen_addresses[$unique_key] = $prop->property_id;

                // Save the cleaned standard data to the DB
                $wpdb->update(
                    'ac_property',
                    [
                        'street_address' => $standardized,
                        'street_name'    => $street_name,
                        'house_number'   => $house_number
                    ],
                    ['property_id' => $prop->property_id],
                    ['%s', '%s', '%s'],
                    ['%d']
                );
                $updated_count++;
            }
        }
    }

    // 3. Set the flag so this never runs again! ('no' means don't auto-load this option on every page load)
    update_option( 'fsbhoa_ac_address_standardization_complete', 'yes', 'no' );

    error_log("Address Cleanup Complete: Standardized {$updated_count} master properties. Merged and removed {$merged_count} duplicates.");
}
// Hook it to admin_init so it triggers the next time you load the dashboard
add_action('admin_init', 'fsbhoa_run_one_time_address_dedupe_and_cleanup');



/**
 * Checks and runs necessary database migrations automatically.
 * Hooked to admin_init so it only runs in the background for logged-in admins.
 */
add_action( 'wp_loaded', 'fsbhoa_check_and_run_migrations' );
function fsbhoa_check_and_run_migrations() {
    // 1. Check if our specific household migration flag exists
    if ( get_option( 'fsbhoa_household_migration_completed' ) ) {
        return; 
    }

    global $wpdb;
    
    // 2. Safety check: ensure BOTH tables physically exist
    $cardholders_exists = $wpdb->get_var("SHOW TABLES LIKE 'ac_cardholders'");
    $households_exists  = $wpdb->get_var("SHOW TABLES LIKE 'ac_households'");
    
    if ( ! $cardholders_exists || ! $households_exists ) {
        error_log("MIGRATION DEBUG: Skipped because tables are missing. Cardholders: " . ($cardholders_exists ? 'YES' : 'NO') . ", Households: " . ($households_exists ? 'YES' : 'NO'));
        return; 
    }

    // 3. Get a list of all properties that currently have eligible occupants
    $properties = $wpdb->get_results(
        "SELECT DISTINCT property_id 
         FROM ac_cardholders 
         WHERE cardholder_status IN ('active', 'inactive') 
           AND (resident_type != 'Landlord' OR resident_type IS NULL) 
           AND property_id > 0"
    );

    if ( empty($properties) ) {
        error_log("MIGRATION DEBUG: No eligible cardholders found matching the criteria.");
        return;
    }

    error_log("MIGRATION DEBUG: Found " . count($properties) . " properties to migrate.");

    foreach ( $properties as $prop ) {
        $resident = $wpdb->get_row($wpdb->prepare(
            "SELECT last_name 
             FROM ac_cardholders 
             WHERE property_id = %d 
               AND cardholder_status IN ('active', 'inactive') 
               AND (resident_type != 'Landlord' OR resident_type IS NULL) 
             LIMIT 1", 
            $prop->property_id
        ));

        if ( $resident ) {
            $household_name = sanitize_text_field( $resident->last_name . ' Household' );

            $wpdb->insert('ac_households', ['household_name' => $household_name]);
            $new_household_id = $wpdb->insert_id;

            if ( $new_household_id ) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE ac_cardholders 
                     SET household_id = %d 
                     WHERE property_id = %d 
                       AND cardholder_status IN ('active', 'inactive') 
                       AND (resident_type != 'Landlord' OR resident_type IS NULL)",
                    $new_household_id,
                    $prop->property_id
                ));
            }
        }
    }
    
    error_log("FSBHOA MIGRATION: Successfully grouped eligible residents into households.");
    update_option( 'fsbhoa_household_migration_completed', '1' );
}

