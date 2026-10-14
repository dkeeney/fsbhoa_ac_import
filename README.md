# FSBHOA Access Control: Resident Import

An extension plugin for the [FSBHOA Access Control system](https://github.com/dkeeney/fsbhoa_ac_core). It keeps the system's residents in step with the HOA's property management system (Vantaca, run by PMP) by importing its homeowner list as a CSV file.

For what the whole system does, how it is put together, and how the plugins relate to each other, start with the core plugin: **[fsbhoa_ac_core](https://github.com/dkeeney/fsbhoa_ac_core)**. This plugin requires core and does nothing without it.

## What it does

The property management system knows who owns or rents each property. This plugin turns that list into cardholders, properties and households in the access-control database:

1. A script on an office PC logs into the property management website, exports the Homeowner List, converts it to CSV and saves it to the import folder on the NAS.
2. The script calls the import's REST API, which reads the file and compares it with the database, one property at a time.
3. Residents in the file are matched to existing cardholders by their formal name. New ones are created and grouped into a household for the property. Residents who have left (in the database from an earlier import but no longer in the file) are archived.
4. The import returns a report of what it created, updated and archived. Where an active cardholder's phone or email differs from the file, it also writes a contact mismatch report to the import folder for staff to review.

Records entered by hand are respected: they are never archived by the import, and one that matches a name in the file is taken over by the import from then on. A current household Primary, including one chosen by hand, is never changed.

Every import can run as a **dry run**, which reports what would change without writing anything.

## How it works

```
Property management website
        │  export (Selenium script on an office PC)
        v
CSV on the NAS ──> POST fsbhoa/v1/import/run (X-API-KEY) ──> WordPress import ──> ac_cardholders,
                   GET  fsbhoa/v1/import/status                                   ac_property,
                                                                                  ac_households
```

The import can also be run by hand: the `[fsbhoa_import_form]` shortcode gives administrators an upload form with a dry-run checkbox.

### Matching and lifecycle

- Each cardholder and property records where it came from (`origin`: `import` or `manual`).
- A resident is matched on the formal name from the file (`import_first_name` / `import_last_name`), not the preferred name shown in the system, so staff can change the display name freely.
- Only records with origin `import` are archived when they disappear from the file. Archiving keeps the record, so it can be restored.
- New residents join the existing household of current imported residents at the same property, or start a new one named "*Last name* Household". The first resident becomes its Primary.

### The CSV file

The file has one row per property. Column names are matched without regard to case, and a few spellings are accepted for each:

| Column | Holds |
|---|---|
| `Property Address` | The property's street address. |
| `First Name`, `Last Name` | The first owner. |
| `Second Owner First Name`, `Second Owner Last Name` | A second owner, if any. |
| `Phone`, `Email` | Owner phone numbers and emails, in owner order. |
| `Tenant Name(s)`, `Tenant Email(s)`, `Tenant Phone(s)` | Tenants, if the property is rented. |

Cells with several entries are matched up **by position**: the second tenant's email is the second entry in `Tenant Email(s)`. Entries are separated by commas or line breaks (including `<br>`), and empty entries keep their place. Phone fields may also use `:`. A tenant's last word is taken as the last name.

Addresses are normalized to USPS standards (with core's `fsbhoa_standardize_address()`) after removing the configured city/state suffix, then split into house number and street to match core's property list. Since core holds every property in the community, an import that creates or changes a property usually points to an address that didn't parse as expected.

The "Lodge" property, which core reserves for vendors and other non-residents, is never used by the import.

## Testbed and production

The testbed and the production server share the NAS. Each server reads its imports from its own subfolder, named after the `FSBHOA_AC_ENVIRONMENT` constant in its `wp-config.php` (`testbed` or `production`), e.g. `/mnt/shared/Automation/Import/production/`. The REST API only accepts a file inside that folder. If the constant is missing or unrecognized, REST imports and the mismatch report are refused and the reason is logged; uploads through the shortcode form still work.

## Requirements

- WordPress with the [fsbhoa_ac_core](https://github.com/dkeeney/fsbhoa_ac_core) plugin active.
- `FSBHOA_AC_ENVIRONMENT` defined in `wp-config.php` for REST imports.
- The NAS share mounted on the server (at `/mnt/shared`), writable by the web server.
- For the automated export, on a Windows PC: Python with `selenium`, `pandas`, `openpyxl`, `requests` and `keyring`, Google Chrome, and the NAS share mapped as a drive.

## Installation

1. Put the repository in WordPress's plugin folder, or symlink it there, then activate **FSBHOA Access Control - Import**:
   ```bash
   ln -s /home/pi/fsbhoa_ac_import /var/www/html/wp-content/plugins/fsbhoa_ac_import
   ```
2. In core's General settings:
   - Generate the **CSV Import API Key**.
   - Set **Address Suffix to Remove** to the city, state and ZIP the property management system adds to every address.
   - Under **Import Settings**, set the **Import Folder** (default `/mnt/shared/Automation/Import`) and save. The page shows the folder this server will use.
3. Create that folder's environment subfolder on the NAS if it doesn't exist yet.
4. Optionally, create a WordPress page containing the shortcode `[fsbhoa_import_form]` for manual uploads.

Only administrators (`manage_options`) can use the upload form. The REST routes require the CSV Import API Key in the `X-API-KEY` header.

### The export script

`scripts/export_and_trigger.py` does the export and starts the import; `scripts/PushToAccessControl.bat` runs it. The script is kept on the NAS so it can be updated without visiting the office, and run from the office PC.

Its credentials (the property management login and the CSV Import API Key) are never stored in the script. They are kept in Windows Credential Manager on the PC that runs it:

```bat
pip install keyring
python export_and_trigger.py --set-credentials
python export_and_trigger.py --dry-run
```

`--set-credentials` asks for each value (leave one blank to keep what is stored), and `--dry-run` runs the whole export with a dry-run import. Without options, the script runs a live import.

## Diagnostics

The plugin adds two steps to core's Diagnostics test suite (through the `fsbhoa_regression_test_steps` filter):

1. **Import folder:** the environment is set and its import folder exists and is writable.
2. **Dry-run import:** a one-row sample CSV is written to the import folder, imported through the REST API as a dry run, and deleted. The report must show 1 row, 2 new cardholders and none archived. The sample uses a made-up address, so it can't affect real residents or the mismatch report.

## License

GPL v2 or later, the same as [fsbhoa_ac_core](https://github.com/dkeeney/fsbhoa_ac_core).
