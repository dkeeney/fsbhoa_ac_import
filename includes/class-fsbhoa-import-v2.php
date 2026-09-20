<?php
/**
 * Handles the CSV import functionality for cardholders (Version 2).
 *
 * This module provides the user interface for uploading a CSV file and processes
 * the file to sync property, owner, and tenant data with the database according
 * to the specifications for Iteration 6. This class is designed to be called
 * by a shortcode.
 *
 * @package    Fsbhoa_Ac
 * @subpackage Fsbhoa_Ac/admin
 * @author     FSBHOA IT Committee
 *
 *
 *
 * Gemini: please do not remove this comment block.
 * To clear the database of all records, do the following;
 *   SET FOREIGN_KEY_CHECKS=0;
 *   
 *   DELETE FROM `ac_property`;
 *   DELETE FROM `ac_cardholders`;
 *   DELETE FROM `ac_access_log`;
 *   
 *   ALTER TABLE `ac_property` AUTO_INCREMENT = 1;
 *   ALTER TABLE `ac_cardholders` AUTO_INCREMENT = 1;
 *   ALTER TABLE `ac_access_log` AUTO_INCREMENT = 1;
 *   
 *   SET FOREIGN_KEY_CHECKS=1;
 *******************************/

if (!defined('WPINC')) {
    die;
}

class Fsbhoa_Import_V2
{
    private $wpdb;
    private $table_cardholders;
    private $table_properties;
    private $feedback = [];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_cardholders = 'ac_cardholders';
        $this->table_properties = 'ac_property';
    }

    /**
     * Main render method for the shortcode. It handles form submission and displays the UI.
     */
    public function render_shortcode_page()
    {
        // Check for form submission before rendering anything
        if (isset($_POST['fsbhoa_import_v2_nonce']) && wp_verify_nonce($_POST['fsbhoa_import_v2_nonce'], 'fsbhoa_import_v2_action')) {
            $this->handle_import_submission();
        }

        // Now, start output buffering to capture the HTML
        ob_start();
        $this->render_import_form();
        return ob_get_clean();
    }
    
    /**
     * Renders the HTML for the import form.
     */
    private function render_import_form()
    {
        if (!current_user_can('manage_options')) {
            echo "<p>You do not have sufficient permissions to perform this action.</p>";
            return;
        }
        ?>
        <div class="fsbhoa-import-wrapper  fsbhoa-frontend-wrap">
            <div class="import-section">
                <h2>Cardholder & Property Sync</h2>

                <?php if (!empty($this->feedback)) : ?>
                    <div class="import-results notice notice-<?php echo esc_attr($this->feedback['type']); ?>">
                        <p><strong><?php esc_html_e('Import Results:', 'fsbhoa-ac'); ?></strong></p>
                        <ul>
                            <?php foreach ($this->feedback['messages'] as $message) : ?>
                                <li><?php echo esc_html($message); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <p>This tool imports or synchronizes all data for addresses, owners, and tenants 
                   from a single CSV file extracted from the property management database. 
                   Any resident that is defined in this file will be added to our access control 
                   database (See Cardholders). Any resident that is not in this CSV file but is 
                   in our access control database will be archived (see Archived Cardholders), 
                   unless that record is marked with an override. 
                    <a href="#" id="open-csv-info-dialog" style="text-decoration: underline;">More info on CSV file content.</a>
                </p>

                <div id="csv-info-dialog" title="CSV File Content" style="display:none;">
                    <p>The columns in the CSV file can be in any order, but the file must contain 
                     a header row with the following titles:</p>
                    <ul class="fsbhoa-info-list">
                        <li><strong>Property Address</strong> -- full address. The city, state, and zip matching the text in WordPress options will be removed.</li>
                        <li><strong>First Name</strong> -- The formal First Name as on deed</li>
                        <li><strong>Last Name</strong> -- The formal Last Name as on deed</li>
                        <li><strong>Second Owner First Name</strong> -- formal first name as on deed</li>
                        <li><strong>Second Owner Last Name</strong> -- formal last name as on deed.</li>
                        <li><strong>Phone</strong> -- comma separated list of corresponding phone numbers</li>
                        <li><strong>Email</strong> -- comma separated list of corresponding email addresses</li>
                        <li><strong>Tenant Names(s)</strong> -- a comma separated list of tenant's Formal names</li>
                        <li><strong>Tenant Email(s)</strong> -- a comma separated list of corresponding emails.</li>
                        <li><strong>Tenant Phone(s)</strong> -- a comma separated list of corresponding phone numbers</li>
                    </ul>
                    <p>A separate cardholder record will be generated for each resident and address 
                       combination; for owner, 2nd owner, and each tenant.</p>
                    <p>Note that if a resident moves from one address to another within the community, 
                       a new cardholder record will be created for the new resident and address 
                       combination and the old cardholder record will be moved to the archive. To 
                       recover the photo and rfid for this resident, go to the Archive Cardholder 
                       screen, click the merge icon, locate the new address, and merge.</p>
                </div>

                <form method="post" action="" enctype="multipart/form-data" class="fsbhoa-form">
                    <?php wp_nonce_field('fsbhoa_import_v2_action', 'fsbhoa_import_v2_nonce'); ?>
                    <p>
                        <label for="csv_import_file"><strong>Select CSV File to Import:</strong></label><br>
                        <input type="file" id="csv_import_file" name="csv_file" accept=".csv, text/csv" required>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="dry_run_import" value="true" checked>
                            <strong>Run as a Dry Run (Test Mode)</strong>
                        </label>
                        <br>
                        <small><em>In Dry Run mode, no changes will be made to the database. The system will only report what it would have done. Uncheck this box to perform a live import.</em></small>
                    </p>
                    <p>
                        <input type="submit" name="submit_import" class="button-primary" value="Import CSV">
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Handles the file upload submission.
     */
    private function handle_import_submission()
    {
        if (!current_user_can('manage_options') || !isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $this->feedback = ['type' => 'error', 'messages' => [__('File upload error or insufficient permissions. Please try again.', 'fsbhoa-ac')]];
            return;
        }
        
        $file_path = sanitize_text_field($_FILES['csv_file']['tmp_name']);

        // Check the state of the new "Dry Run" checkbox from the form.
        // The 'isset' check works because an unchecked checkbox is not submitted with the form data.
        $is_dry_run = isset($_POST['dry_run_import']);

        $this->feedback = $this->process_csv_file($file_path, $is_dry_run);
    }
    
    
    /**
     * Core processing logic that reads and syncs data from the CSV file.
     * @param string $file_path The temporary server path to the uploaded CSV file.
     * @return array An array containing feedback messages and status.
     */
    function process_csv_file($file_path,  $is_dry_run = false)
    {
        $mismatched_contacts = [];
        $stats = [
            'rows_processed' => 0,
            'properties_created' => 0,
            'cardholders_created' => 0,
            'cardholders_updated' => 0,
            'cardholders_archived' => 0,
            'properties_deleted' => 0,
            'landlords_identified' => 0,
            'errors' => [],
        ];

        $address_suffix_to_remove = get_option('fsbhoa_ac_address_suffix', '');
        $handle = fopen($file_path, 'r');
        if ($handle === false) {
            return ['type' => 'error', 'messages' => [__('Could not open the uploaded file.', 'fsbhoa-ac')]];
        }

        $header_raw = fgetcsv($handle, 0, ",", "\"", "\\");
        if ($header_raw === false) {
             return ['type' => 'error', 'messages' => [__('Could not read the header row from the CSV file.', 'fsbhoa-ac')]];
        }

        // Detect and remove UTF-8 BOM from the first header element if it exists
        if (isset($header_raw[0]) && strpos($header_raw[0], "\xEF\xBB\xBF") === 0) {
            $header_raw[0] = substr($header_raw[0], 3);
        }

        $header = array_map('trim', array_map('strtolower', $header_raw));

        while (($row_data = fgetcsv($handle, 0, ",", "\"", "\\")) !== false) {
            $stats['rows_processed']++;
            // Pad the row data with empty strings if it has fewer columns than the header
            $row_data = array_pad($row_data, count($header), '');

            // ---  CSV ROW NORMALIZATION ---
            $header_count = count($header);
            $row_count = count($row_data);

            if ($row_count < $header_count) {
                // If the row is too short, pad it with empty strings.
                $row_data = array_pad($row_data, $header_count, '');
            } elseif ($row_count > $header_count) {
                // If the row is too long, truncate it to the header length.
                $row_data = array_slice($row_data, 0, $header_count);
                $stats['errors'][] = "Row " . ($stats['rows_processed'] + 1) . ": Warning - row has more columns than the header and was truncated.";
            }
            // Now we can be 100% certain the arrays have the same number of elements.
            $row = array_combine($header, $row_data);

            try {
                $new_cardholders_from_row = $this->parse_cardholders_from_row($row);

                $property_address_raw = $this->get_value_from_row($row, ['property address', 'property_address']);
                $property_id = $this->get_or_create_property($property_address_raw, $stats, $is_dry_run);
                if (!$property_id) {
                    throw new Exception("Skipping row due to missing or invalid property address.");
                }

                $existing_db_cardholders = $this->get_cardholders_by_property($property_id);
                $this->sync_property_occupants($new_cardholders_from_row, $existing_db_cardholders, $property_id, $stats, $is_dry_run);
                $this->apply_changes_to_db($new_cardholders_from_row, $property_id, $property_address_raw, $stats, $is_dry_run, $mismatched_contacts);

            } catch (Exception $e) {
                $stats['errors'][] = "Row " . ($stats['rows_processed'] + 1) . ": " . $e->getMessage();
            }
        }
        fclose($handle);

        // --- Generate Mismatch Report ---
        $report_result = $this->generate_mismatch_report($mismatched_contacts);

        // --- Perform Property Cleanup ---
        $properties_deleted = $this->cleanup_unused_properties($is_dry_run);
        $stats['properties_deleted'] = $properties_deleted;
        
        $feedback_messages = [
            sprintf(__("Import complete. Processed %d rows.", 'fsbhoa-ac'), $stats['rows_processed']),
            sprintf(__("Properties Created:   %d", 'fsbhoa-ac'), $stats['properties_created']),
            sprintf(__("Cardholders Created:  %d", 'fsbhoa-ac'), $stats['cardholders_created']),
            sprintf(__("Cardholders Updated:  %d", 'fsbhoa-ac'), $stats['cardholders_updated']),
            sprintf(__("Cardholders Archived: %d", 'fsbhoa-ac'), $stats['cardholders_archived']),
            sprintf(__("Properties Deleted:   %d", 'fsbhoa-ac'), $stats['properties_deleted']),
            //sprintf(__("Owner sets updated to 'Landlord': %d", 'fsbhoa-ac'), $stats['landlords_identified']),
        ];

        // Add a message to the results if this was a dry run
        if ($is_dry_run) {
            array_unshift($feedback_messages, "--- DRY RUN MODE: No changes were made to the database. ---");
        }


        if ($report_result) {
            // Check if the result is an error message or a file path
            if (strpos($report_result, 'Error:') === 0) {
                $feedback_messages[] = "Contact Mismatch Report Generation Failed: " . esc_html($report_result);
            } else {
                $feedback_messages[] = "Contact Mismatch Report Generated: " . esc_html($report_result);
            }
        }
	
        if (!empty($stats['errors'])) {
            $feedback_messages[] = __("--- The following errors occurred: ---", 'fsbhoa-ac');
            $feedback_messages = array_merge($feedback_messages, $stats['errors']);
        }
        
        return [ 'type' => empty($stats['errors']) ? 'success' : 'warning', 'messages' => $feedback_messages ];
    }
    

    private function sync_property_occupants(&$new_cardholders_from_row, $existing_db_cardholders, $property_id, &$stats, $is_dry_run)
    {
        // Compare based on the formal import name, not the preferred display name
        $new_full_names = array_map(function ($ch) { 
            return strtolower(trim($ch['import_first_name'])) . ' ' . strtolower(trim($ch['import_last_name'])); 
        }, $new_cardholders_from_row);

        foreach ($existing_db_cardholders as $db_cardholder) {
            // Compare based on the formal import name stored in the database
            $existing_full_name = strtolower(trim($db_cardholder->import_first_name)??'') . ' ' . strtolower(trim($db_cardholder->import_last_name));
            
            // If an existing person from an import is NOT in the new import file, delete them.
            if (!in_array($existing_full_name, $new_full_names)) {
                if ($db_cardholder->origin === 'import') {

                    // Only archive if it's not a dry run
                    if (!$is_dry_run) {
                        $result = fsbhoa_archive_and_delete_cardholder($db_cardholder->id);

                        if (is_wp_error($result)) {
                            $stats['errors'][] = "Row " . ($stats['rows_processed'] + 1) . ": Could not archive '{$db_cardholder->first_name} {$db_cardholder->last_name}'. Reason: " . $result->get_error_message();
                        } else {
                            $stats['cardholders_archived']++;
                        }
                    } else {
                        // In dry run mode, just increment the stat
                        $stats['cardholders_archived']++;
                    }
                }
            }
            
            // If a manually created user exists with an EXACT name match, promote it to an import record
            // and turn off the manual flag so it transitions seamlessly into the managed lifecycle.
            if ($db_cardholder->origin !== 'import' && in_array(strtolower(trim($db_cardholder->first_name)) . ' ' . strtolower(trim($db_cardholder->last_name)), $new_full_names)) {

                if (!$is_dry_run) {
                    $update_fields = [
                        'origin'            => 'import',
                        'import_first_name' => trim($db_cardholder->first_name),
                        'import_last_name'  => trim($db_cardholder->last_name),
                    ];

                    $this->wpdb->update(
                        $this->table_cardholders,
                        $update_fields,
                        ['id' => $db_cardholder->id]
                    );

                    // Update the in-memory object for subsequent steps in this run
                    $db_cardholder->origin            = 'import';
                    $db_cardholder->import_first_name = trim($db_cardholder->first_name);
                    $db_cardholder->import_last_name  = trim($db_cardholder->last_name);
                }

                // Keep the record in $new_cardholders_from_row so apply_changes_to_db()
                // can match it via import_first_name / import_last_name and sync contact details.
            }
        }

        /****************************************************************************
        ***************** comment this code out.  Might need it later however.  ****
         *************So, do not remove this block.    ******************************
        // If the cardholder is a tenent then any owners at that address are landlords
        // assuming that the owner does not live there.
        // HOWEVER: We don't know if the owner also lives there so this may not be valid.
        $has_tenants = false;
        foreach ($new_cardholders_from_row as $cardholder) { 
            if ($cardholder['resident_type'] === 'Tenant') { 
                $has_tenants = true; 
                break; 
            } 
        }
        if (!$has_tenants) {
            foreach ($existing_db_cardholders as $db_cardholder) {
                $existing_full_name = strtolower(trim($db_cardholder->first_name)) . ' ' . strtolower(trim($db_cardholder->last_name));
                if ($db_cardholder->resident_type === 'Tenant' && in_array($existing_full_name, $new_full_names)) { 
                    $has_tenants = true; 
                    break; 
                }
            }
        }
        if ($has_tenants) {
            foreach ($new_cardholders_from_row as &$cardholder) {
                if ($cardholder['resident_type'] !== 'Tenant') {
                    $cardholder['resident_type'] = 'Landlord';
                }
            }
            unset($cardholder);
        }
        ***************** end of commented out block.   *******************/
    }

private function parse_cardholders_from_row($row)
    {
        $parsed_cardholders = [];

        $owner1_first = $this->get_value_from_row($row, ['first name', 'firstname', 'first_name']);
        $owner1_last = $this->get_value_from_row($row, ['last name', 'lastname', 'last_name']);

        $owner2_first = $this->get_value_from_row($row, ['second owner first name', 'secondownerfirstname', 'second_owner_first_name']);
        $owner2_last = $this->get_value_from_row($row, ['second owner last name', 'secondownerlastname', 'second_owner_last_name']);
        
        $phones_str = $this->get_value_from_row($row, ['phone', 'phonenumber']);
        $phones_str_cleaned = str_replace(':', ',', $phones_str);
        $owner_phones = !empty($phones_str_cleaned) ? array_map('trim', explode(',', $phones_str_cleaned)) : [];
        
        $emails_str = $this->get_value_from_row($row, ['email', 'emailaddress']);
        $owner_emails = !empty($emails_str) ? array_map('trim', explode(',', $emails_str)) : [];

        $tenant_names_str = $this->get_value_from_row($row, ['tenant name(s)', 'tenantname(s)', 'tenant_name(s)']);
        $tenant_emails_str = $this->get_value_from_row($row, ['tenant email(s)', 'tenantemail(s)', 'tenant_email(s)']);
        $tenant_phones_str = $this->get_value_from_row($row, ['tenant phone(s)', 'tenantphone(s)', 'tenant_phone(s)']);

        // Owner 1
        if (!empty($owner1_first) && !empty($owner1_last)) {
            $email1 = $owner_emails[0] ?? '';
            $parsed_cardholders[] = [
                'first_name'        => trim($owner1_first),
                'last_name'         => trim($owner1_last),
                'import_first_name' => trim($owner1_first),
                'import_last_name'  => trim($owner1_last),
                'title'             => '', // Manually entered
                'email'             => $email1,
                'email_used'        => !empty($email1) ? 1 : 0, // Set default based on email presence
                'phone'             => $this->normalize_phone($owner_phones[0] ?? ''),
                'resident_type'     => 'Resident Owner',
                'origin'            => 'import',
            ];
        }

        // Owner 2
        if (!empty($owner2_first) && !empty($owner2_last)) {
            $email2 = $owner_emails[1] ?? '';
            $parsed_cardholders[] = [
                'first_name'        => trim($owner2_first),
                'last_name'         => trim($owner2_last),
                'import_first_name' => trim($owner2_first),
                'import_last_name'  => trim($owner2_last),
                'title'             => '', // Manually entered
                'email'             => $email2,
                'email_used'        => !empty($email2) ? 1 : 0, // Set default based on email presence
                'phone'             => $this->normalize_phone($owner_phones[1] ?? ''),
                'resident_type'     => 'Resident Owner',
                'origin'            => 'import',
            ];
        }

        // Tenants
        if (!empty($tenant_names_str)) {
            // Split by comma, newline (\n or \r), or any variation of <br> tags
            $split_pattern = '/(,|[\n\r]|<br\s*\/?>|<\/br>)/i';
            $tenant_names = preg_split($split_pattern, $tenant_names_str, -1, PREG_SPLIT_NO_EMPTY);
            $tenant_names = array_map('trim', $tenant_names);
            $tenant_emails = preg_split($split_pattern, $tenant_emails_str, -1, PREG_SPLIT_NO_EMPTY);
            $tenant_emails = array_map('trim', $tenant_emails);
            $tenant_phones_str_cleaned = str_replace(':', ',', $tenant_phones_str);
            $tenant_phones = preg_split($split_pattern, $tenant_phones_str_cleaned, -1, PREG_SPLIT_NO_EMPTY);
            $tenant_phones = array_map('trim', $tenant_phones);

            foreach ($tenant_names as $index => $name) {
                $name_parts = array_filter(explode(' ', trim($name)));
                if (count($name_parts) < 2) continue;

                $last_name = array_pop($name_parts);
                $first_name = implode(' ', $name_parts);
                $tenant_email = $tenant_emails[$index] ?? '';

                $parsed_cardholders[] = [
                    'first_name'        => $first_name,
                    'last_name'         => $last_name,
                    'import_first_name' => $first_name,
                    'import_last_name'  => $last_name,
                    'title'             => '', // Manually entered
                    'email'             => $tenant_email,
                    'email_used'        => !empty($tenant_email) ? 1 : 0, // Set default based on email presence
                    'phone'             => $this->normalize_phone($tenant_phones[$index] ?? ''),
                    'resident_type'     => 'Tenant',
                    'origin'            => 'import',
                ];
            }
        }
        return $parsed_cardholders;
    }

    private function get_or_create_property($raw_address, &$stats, $is_dry_run)
    {
        if (empty(trim($raw_address))) {
            return null;
        }

        // 1. Clean the raw address from the CSV
        $address_suffix_to_remove = get_option('fsbhoa_ac_address_suffix', '');
        $clean_address = preg_replace('/\s+/u', ' ', trim($raw_address)); // Normalize whitespace
        if (!empty($address_suffix_to_remove)) {
            $clean_address = preg_replace('/' . preg_quote(trim($address_suffix_to_remove), '/') . '$/i', '', $clean_address);
            $clean_address = trim($clean_address);
        }

        // --- Apply USPS Standardization ---
        if ( function_exists('fsbhoa_standardize_address') ) {
            $clean_address = fsbhoa_standardize_address($clean_address);
        }

        if (empty($clean_address)) {
            return null; // Address was just the suffix, so it's empty
        }

        // 2. Parse the cleaned address into house number and street name
        if (!preg_match('/^([0-9]+[A-Z]?)\s+(.*)/', $clean_address, $matches)) {
            throw new Exception("Could not parse address '{$clean_address}'. It must start with a house number.");
        }
        $house_number = trim($matches[1]);
        $street_name = trim($matches[2]);
error_log("[IMPORT DEBUG] Searching for property with House Number: '{$house_number}' and Street Name: '{$street_name}'");
        // 3. Check if property exists based on the new split fields
        $query = $this->wpdb->prepare(
            "SELECT property_id, origin FROM {$this->table_properties} WHERE house_number = %s AND street_name = %s",
            $house_number,
            $street_name
        );
        $property_record = $this->wpdb->get_row($query);

        if ($this->wpdb->last_error) {
            throw new Exception("Database error while checking for property '{$clean_address}': " . $this->wpdb->last_error);
        }

        if ($property_record) {
error_log("[IMPORT DEBUG] FOUND a property record. ID: {$property_record->property_id}, Origin: {$property_record->origin}");
            // --- LOGIC TO "PROMOTE" A MANUAL PROPERTY ---
            // If the property exists and was manually created, the import overrides it as the source of truth.
            if ($property_record->origin === 'manual' && !$is_dry_run) {
error_log("[IMPORT DEBUG] Property is 'manual', attempting to promote to 'import'.");
                $this->wpdb->update(
                    $this->table_properties,
                    ['origin' => 'import'], // Promote to 'import'
                    ['property_id' => $property_record->property_id]
                );
            }
            return (int) $property_record->property_id;
        } else {
error_log("[IMPORT DEBUG] DID NOT FIND a property record. Will attempt to create a new one.");
            if (!$is_dry_run) {
                // 4. Create new property, populating all three address columns
                $result = $this->wpdb->insert(
                    $this->table_properties,
                    [
                        'house_number'   => $house_number,
                        'street_name'    => $street_name,
                        'street_address' => $clean_address, // Populate legacy field
                        'origin'         => 'import'
                    ],
                    ['%s', '%s', '%s', '%s']
                );

                if ($result === false) {
                    throw new Exception("Database error: Could not create property for address '{$clean_address}'. DB Error: " . $this->wpdb->last_error);
                }
            }
            $stats['properties_created']++;
            return $this->wpdb->insert_id;
        }
    }

    private function get_cardholders_by_property($property_id) { 
        $query = $this->wpdb->prepare(
            "SELECT * FROM {$this->table_cardholders} WHERE property_id = %d AND cardholder_status NOT IN ('archived', 'purged')",
            $property_id
        );
        return $this->wpdb->get_results($query);
    }
    
    
    private function apply_changes_to_db($new_list, $property_id, $property_address, &$stats, $is_dry_run, &$mismatched_contacts)
    {
        foreach ($new_list as $cardholder_data) {
            $cardholder_data['property_id'] = $property_id;
            
            // Find existing records using the IMPORT names as the key
            $query = $this->wpdb->prepare(
                "SELECT id, phone, email, resident_type, import_first_name, import_last_name, cardholder_status
                    FROM {$this->table_cardholders} 
                    WHERE import_first_name = %s 
                      AND import_last_name = %s 
                      AND property_id = %d
                      AND cardholder_status NOT IN ('archived', 'purged')",
                $cardholder_data['import_first_name'],
                $cardholder_data['import_last_name'],
                $property_id
            );
            $existing_record = $this->wpdb->get_row($query);

            if ($this->wpdb->last_error) {
                throw new Exception("DB error checking for cardholder '{$cardholder_data['first_name']} {$cardholder_data['last_name']}': " . $this->wpdb->last_error);
            }

            if ($existing_record) {

                // Check for contact info mismatches, BUT only for active cardholders.
                if ($existing_record->cardholder_status === 'active') {
                    $db_phone = trim($existing_record->phone);
                    $csv_phone = trim($cardholder_data['phone']);
                    $db_email = trim(strtolower($existing_record->email));
                    $csv_email = trim(strtolower($cardholder_data['email']));

                    if ($db_phone !== $csv_phone || $db_email !== $csv_email) {
                        $mismatched_contacts[] = [
                            'full_name'        => trim($existing_record->import_first_name . ' ' . $existing_record->import_last_name),
                            'property_address' => $property_address,
                            'db_phone'         => $db_phone,
                            'csv_phone'        => $csv_phone,
                            'db_email'         => $db_email,
                            'csv_email'        => $csv_email,
                        ];
                    }
                }

                // UPDATE existing record
                $data_to_update = [];

                /*******************************************************
                 **********   COMMENT OUT THIS BLOCK, DO NOT REMOVE ****
                 *******************************************************
                 *
                 *
                // Update contact info if changed
                if ($existing_record->phone !== $cardholder_data['phone']) {
                    $data_to_update['phone'] = $cardholder_data['phone'];
                }
                if ($existing_record->email !== $cardholder_data['email']) {
                    $data_to_update['email'] = $cardholder_data['email'];
                }
                
                // Update resident type if changed
                if ($existing_record->resident_type !== $cardholder_data['resident_type']) {
                    $data_to_update['resident_type'] = $cardholder_data['resident_type'];
                    if ($cardholder_data['resident_type'] === 'Landlord') {
                        $stats['landlords_identified']++;
                    }
                }
                *
                *
                *************** End of commented out block ************/

                // Also update the import_name fields in case of a formal name change
                if ($existing_record->import_first_name !== $cardholder_data['import_first_name']) {
                    $data_to_update['import_first_name'] = $cardholder_data['import_first_name'];
                }
                if ($existing_record->import_last_name !== $cardholder_data['import_last_name']) {
                    $data_to_update['import_last_name'] = $cardholder_data['import_last_name'];
                }

                if (!empty($data_to_update)) {
                    // Only update if it's not a dry run
                    if (!$is_dry_run) {
                        $result = $this->wpdb->update($this->table_cardholders, $data_to_update, ['id' => $existing_record->id]);
                        if ($result === false) {
                            throw new Exception("DB error updating cardholder '{$cardholder_data['first_name']} {$cardholder_data['last_name']}'. DB Error: " . $this->wpdb->last_error);
                        }
                    }
                    $stats['cardholders_updated']++;
                }
            } else {
                // INSERT new record
                // The $cardholder_data array from parse_cardholders_from_row now contains all needed fields

                // Find an existing household at this property (ignoring standalone types like Contractors)
                $existing_household = $this->wpdb->get_var($this->wpdb->prepare(
                    "SELECT household_id FROM {$this->table_cardholders}
                     WHERE property_id = %d
                       AND household_id IS NOT NULL
                       AND resident_type NOT IN ('Contractor', 'Staff', 'Other', 'Emergency', 'Delivery')
                     LIMIT 1",
                    $property_id
                ));

                if ($existing_household) {
                    // Join the existing household
                    $cardholder_data['household_id'] = $existing_household;
                } else {
                    // Create a new household
                    $safe_last_name = !empty($cardholder_data['last_name']) ? trim($cardholder_data['last_name']) : 'New';
                    $household_name = sanitize_text_field($safe_last_name . ' Household');

                    if (!$is_dry_run) {
                        $this->wpdb->insert('ac_households', ['household_name' => $household_name]);
                        $cardholder_data['household_id'] = $this->wpdb->insert_id;
                    }
                }

                // Only insert if it's not a dry run
                if (!$is_dry_run) {
                    $result = $this->wpdb->insert($this->table_cardholders, $cardholder_data);
                    if ($result === false) {
                        throw new Exception("DB error inserting cardholder '{$cardholder_data['first_name']} {$cardholder_data['last_name']}'. DB Error: " . $this->wpdb->last_error);
                    }
$new_cardholder_id = $this->wpdb->insert_id;
                    $default_groups = $this->wpdb->get_col("SELECT group_id FROM ac_groups WHERE is_default = 1");
                    if (!empty($default_groups)) {
                        foreach ($default_groups as $group_id) {
                            $this->wpdb->insert('ac_cardholder_groups', [
                                'cardholder_id' => $new_cardholder_id,
                                'group_id' => $group_id
                            ]);
                        }
                    }
                }
                $stats['cardholders_created']++;
            }
        }
    }

    /**
     * Flexibly gets a value from a CSV row by checking for multiple possible header keys.
     * @param array $row            The associative array for the CSV row.
     * @param array $possible_keys  An array of possible lowercase keys to check for.
     * @param string $default       The value to return if no key is found.
     * @return string
     */
    private function get_value_from_row($row, $possible_keys, $default = '') {
        foreach ($possible_keys as $key) {
            if (isset($row[$key])) {
                return $row[$key];
            }
        }
        return $default;
    }


    private function normalize_phone($phone) { 
        $digits = preg_replace('/[^0-9]/', '', $phone); 
        if (strlen($digits) == 11 && substr($digits, 0, 1) == '1') 
            return substr($digits, 1); 
        return (strlen($digits) == 10) ? $digits : $phone; 
    }

    /**
     * Deletes unused, import-generated properties from the database.
     *
     * This function deletes only properties that were
     * created by an import AND have no cardholders whatsoever linked to them.
     *
     * @param bool $is_dry_run If true, only reports what would be deleted.
     * @return int The number of properties deleted or that would be deleted.
     */
    private function cleanup_unused_properties($is_dry_run)
    {
        global $wpdb;
        $property_table = 'ac_property';
        $cardholder_table = 'ac_cardholders';
    
        // The SQL to find property IDs that are eligible for deletion.
        // They must originate from an 'import' AND not have any matching
        // records in the cardholders table.
        $sql_find_orphans = "
            SELECT p.property_id
            FROM {$property_table} AS p
            LEFT JOIN {$cardholder_table} AS c ON p.property_id = c.property_id
            WHERE p.origin = 'import' AND c.id IS NULL
        ";

        $properties_to_delete_ids = $wpdb->get_col($sql_find_orphans);
    
        if (empty($properties_to_delete_ids)) {
            return 0;
        }

        $count = count($properties_to_delete_ids);

        if (!$is_dry_run) {
            $placeholders = implode(',', array_fill(0, $count, '%d'));
            $sql_delete = $wpdb->prepare(
                "DELETE FROM {$property_table} WHERE property_id IN ($placeholders)",
                $properties_to_delete_ids
            );
            $wpdb->query($sql_delete);
        }

        return $count;
    }


    /**
     * Generates a CSV report of contact information mismatches to the NAS.
     *
     * @param array $mismatches The array of mismatched data.
     * @return string|false The path to the report file, an error string, or false.
     */
    private function generate_mismatch_report($mismatches) {
        if (empty($mismatches)) {
            return false;
        }

        $report_dir = '/mnt/shared/AccessControl/';

        // Check if the NAS directory is writable by the web server user (e.g., www-data)
        if (!is_writable($report_dir)) {
            return "Error: Report directory {$report_dir} is not writable by the web server.";
        }

        $filename = 'contact_mismatch_report.csv';
        $filepath = $report_dir . $filename;

        $handle = fopen($filepath, 'w');
        if (!$handle) {
            return "Error: Could not open file for writing at {$filepath}";
        }

        // Write header row
        fputcsv($handle, ['Name', 'Address', 'DB Phone', 'Import Phone', 'DB Email', 'Import Email']);

        // Write data rows
        foreach ($mismatches as $row) {
            fputcsv($handle, [
                $row['full_name'],
                $row['property_address'],
                $row['db_phone'],
                $row['csv_phone'],
                $row['db_email'],
                $row['csv_email'],
            ]);
        }

        fclose($handle);

        return "Y:/AccessControl/".$filename; // Return the full server path of the generated report
    }
}

