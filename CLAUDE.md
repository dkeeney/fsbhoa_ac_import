This plugin extends fsbhoa_ac_core.  @/home/pi/fsbhoa_ac_core/other_docs/ARCHITECTURE.md

## Purpose

Interface to the HOA's property management system. AutoHotKey scripts click through the property management system's web interface to export a `.csv` file. That file is copied to the NAS import folder (see [Import folder](#import-folder)), and the script then calls `POST /fsbhoa/v1/import/run` (authorized with the `X-API-KEY` header against the `fsbhoa_ac_api_key` option) to import it. `GET /fsbhoa/v1/import/status` reports the last run.

The CSV has exactly one row per property. A property on two rows would be an error in the property management database; the import doesn't guard against it, and the second row would archive the imported residents from the first.

## Import model

The property management system is property-oriented, so the import extracts the members of each household as listed on deeds and rental contracts.

Cardholders are matched on their **formal name** (`import_first_name` / `import_last_name`), not the preferred display name. The `origin` field on `ac_cardholders` and `ac_property` records whether a record came from the import (`'import'`) or was created by hand (`'manual'`).

- **Match on an `'import'` cardholder:** the record stays and its contact details are synced.
- **Match on a cardholder with any other origin:** the record is promoted to `'import'` and its formal name is set from its current name (`includes/class-fsbhoa-import-v2.php`).
- **No match:** a new cardholder is created with origin `'import'`.
- **`'import'` cardholder missing from the new file:** archived via `fsbhoa_archive_and_delete_cardholder()`. That function only sets `cardholder_status = 'archived'`; the row and its `household_id` remain.
- **Cardholder with any other origin (e.g. `'manual'`) missing from the new file:** never archived.
- Properties follow the same pattern: a `'manual'` property found in the import is promoted to `'import'`.

## CSV lists

Owner and tenant contact fields hold several entries in one cell, matched up **by position**: the second tenant name goes with the second tenant email and phone. The property management system has no other way to tie them together, so `split_positional_list()` keeps empty slots (`a@x.com,,c@x.com` is three entries). Entries are separated by commas or line breaks (including `<br>` tags); a comma next to a line break counts as one separator. In phone fields `:` is also a separator.

A name is never skipped for having only one part. For tenants, the last word is the last name and the rest is the first name, so a single-word name becomes the last name with an empty first name. An owner is created if either the first or last name column is filled in. Only a completely blank name is skipped.

## Addresses

The import doesn't store the raw address string from the property management system. It normalizes each address to USPS standards and stores that. The steps are:

1. Collapse whitespace.
2. Strip the configured suffix (option `fsbhoa_ac_address_suffix`).
3. Apply core's `fsbhoa_standardize_address()` (`fsbhoa_ac_core/includes/fsbhoa-cardholder-functions.php`).
4. Split the result into `house_number` and `street_name` to match `ac_property`.

`ac_property` now holds every property in the community, so the import should rarely create or change a property. If it does, treat that as a likely parsing or normalization problem first.

**"Lodge"** is a dummy property that holds all non-residents (staff, contractors, etc.). It is not a real residence. Core reserves it for vendors and blocks assigning residents to it. The import must never assign residents to it, create it, or archive it.

## Households

The import groups all residents at the same property into one `ac_households` record. Membership is stored on the cardholder (`ac_cardholders.household_id`), not as a list in `ac_households`.

When it inserts a new cardholder, the import looks for an existing household at that property among current imported residents only (`origin = 'import'`, `cardholder_type = 'resident'`, `cardholder_status` `active`).
- Archived cardholders keep their `household_id`, so without the status filter a new owner would join the previous owner's household.
- Manual records are never archived, so without the origin filter a new owner would join a household left with only manual members. Such a manual record may be the same person entered by hand under a slightly different name, or someone else. The import can't tell, so it creates a new household, and an admin merges the near-duplicate records later.

The lookup also ignores the non-resident types `Contractor`, `Staff`, `Other`, `Emergency` and `Delivery`. If it finds one, the cardholder joins it. If not, it creates a new household named "`<last name>` Household".

The first resident in a household is its **Primary** (`ac_households.primary_cardholder_id`). After each property is processed, `ensure_household_primaries()` checks that property's households. Any household with no Primary, or whose Primary is archived, purged or missing, gets its lowest-id `active` member. A current Primary is never changed, so one chosen by hand in the UI is kept.

Existing households get their Primary from the backfill in core's `fsbhoa_ac_core/migration.sql`, not from this plugin.

Imports support a dry-run mode that counts changes without writing them.

## Import folder

The `fsbhoa_import_folder` option (default `/mnt/shared/AccessControl`) sets the import folder, with the `FSBHOA_AC_ENVIRONMENT` value appended, e.g. `/mnt/shared/AccessControl/testbed/`. It appears as an "Import Settings" section on core's General Settings page (`includes/class-fsbhoa-import-settings.php`, `Fsbhoa_Import_Settings::environment_dir()`).

- `POST /fsbhoa/v1/import/run` only accepts a `file_path` inside this folder (checked with `realpath`). The AutoHotKey script must place the CSV there and pass that path.
- When an active cardholder's phone or email differs from the CSV, the import writes `contact_mismatch_report.csv` to this folder.
- If the environment is missing or unrecognized, both REST imports and the report are refused, and the reason is logged. Imports uploaded through the shortcode form still work, since they don't read from the NAS.
