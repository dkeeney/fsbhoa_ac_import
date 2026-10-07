# Import TODO

- [ ] **1. Add the import test to core's Diagnostics test suite.** Core used to run a CSV import dry run itself. It was removed from core on 2026-10-07 because it called this plugin's REST endpoint and broke once imports were limited to the environment's import folder. Core's test wrote the CSV to the server's temp folder, and `validate_import_file_path` refused it ("File must be inside the import folder /mnt/shared/AccessControl/testbed/").
  - Add the steps here with core's `fsbhoa_regression_test_steps` filter. Each step is `[ 'id', 'label', 'callback' ]`, and the callback returns a success message or a `WP_Error`. The kiosk plugin's `includes/class-fsbhoa-kiosk-regression-test.php` is an example.
  - The test should write its sample CSV inside `Fsbhoa_Import_Settings::environment_dir()`, call `POST /wp-json/fsbhoa/v1/import/run` with `dry_run: true` and the import API key (`fsbhoa_ac_api_key`), delete the file, and check the response has a `messages` array.
  - If `FSBHOA_AC_ENVIRONMENT` isn't set, fail with that reason rather than skipping.
  - The suite stops at the first failed step, so a failing import step stops every step after it.
  - Core's old version is in `fsbhoa_ac_core` git history (`run_import_test` and `verify_import_test` in `includes/admin/class-fsbhoa-test-suite-actions.php`, before 2026-10-07).
