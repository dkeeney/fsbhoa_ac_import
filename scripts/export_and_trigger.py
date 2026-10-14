# === HOA Export and Trigger Script  ===
#  9/22/2025, dkeeney@gmail.com
#
# Usage (from the office PC that runs the export):
#   python export_and_trigger.py                    export and run a live import
#   python export_and_trigger.py --dry-run          export and run a dry-run import (no database changes)
#   python export_and_trigger.py --set-credentials  store the Vantaca login and the import API key
#
# The script lives on the NAS, but the Vantaca login and the import API key are kept in
# Windows Credential Manager on the PC that runs it (Python's keyring package), never in
# this file, so they stay off the NAS, its backups and git. Requires: pip install keyring

import time
import shutil
import os
import glob
import pandas as pd
import requests
import json
import sys
import getpass
import keyring
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.common.action_chains import ActionChains

# --- 1. CONFIGURATION ---
LOGIN_URL = "https://e9.vantaca.net/Home/Login"

# --- Credentials (Windows Credential Manager, under this service name) ---
CREDENTIAL_SERVICE = "fsbhoa_resident_import"
CREDENTIAL_FIELDS = [
    ("vantaca_username", "Vantaca username", False),
    ("vantaca_password", "Vantaca password", True),
    ("api_key", "Import API key (access control fsbhoa_ac_api_key)", True),
]

# --- CSS SELECTORS ---
COMPANY_ID_SELECTOR = "#companyID"
USERNAME_SELECTOR = "#login"
PASSWORD_SELECTOR = "#password"
LOGIN_BUTTON_SELECTOR = "button[type='submit']"

# --- File Paths ---
DOWNLOAD_DIR = f"C:\\Users\\{os.getlogin()}\\Downloads"
NAS_PATH = "Y:\\AccessControl\\ResidentImport.csv"

# --- API Configuration ---
API_URL = "https://access.fsbhoa.com/wp-json/fsbhoa/v1/import/run"
SERVER_FILE_PATH = "/mnt/shared/AccessControl/ResidentImport.csv"


def set_credentials():
    """Asks for each credential and stores it in Credential Manager. Leave a field blank to keep its stored value."""
    print("Storing credentials in Windows Credential Manager for this PC and Windows login.")
    for key, label, secret in CREDENTIAL_FIELDS:
        current = keyring.get_password(CREDENTIAL_SERVICE, key)
        prompt = f"{label}{' (blank keeps the stored value)' if current else ''}: "
        value = getpass.getpass(prompt) if secret else input(prompt)
        if value.strip():
            keyring.set_password(CREDENTIAL_SERVICE, key, value.strip())
        elif not current:
            print(f"    WARNING: {label} is not set.")
    print("Done.")


def get_credentials():
    """Returns the stored credentials, or None (after saying which are missing) if any are not set."""
    values = {key: keyring.get_password(CREDENTIAL_SERVICE, key) for key, _, _ in CREDENTIAL_FIELDS}
    missing = [label for key, label, _ in CREDENTIAL_FIELDS if not values[key]]
    if missing:
        print("--- MISSING CREDENTIALS ---")
        print("Not stored on this PC: " + ", ".join(missing))
        print(f'Run: python "{os.path.abspath(__file__)}" --set-credentials')
        return None
    return values


# --- 2. SCRIPT LOGIC ---
if "--set-credentials" in sys.argv:
    set_credentials()
    input("\nPress Enter to exit.")
    sys.exit(0)

DRY_RUN = "--dry-run" in sys.argv
credentials = get_credentials()
if not credentials:
    input("\nScript finished. Press Enter to exit.")
    sys.exit(1)
USERNAME = credentials["vantaca_username"]
PASSWORD = credentials["vantaca_password"]
API_KEY = credentials["api_key"]

print("--- Starting HOA Export and Import Trigger ---")
if DRY_RUN:
    print("*** DRY RUN: no changes will be made to the database ***")
options = webdriver.ChromeOptions()
prefs = {"download.default_directory": DOWNLOAD_DIR}
options.add_experimental_option("prefs", prefs)
driver = webdriver.Chrome(options=options)
driver.implicitly_wait(5)

try:
    print("\n>>> Step 1: Logging into the Property Management website...")
    driver.get(LOGIN_URL)
    
    WebDriverWait(driver, 10).until(EC.visibility_of_element_located((By.CSS_SELECTOR, USERNAME_SELECTOR))).send_keys(USERNAME)
    driver.find_element(By.CSS_SELECTOR, PASSWORD_SELECTOR).send_keys(PASSWORD)
    driver.find_element(By.CSS_SELECTOR, COMPANY_ID_SELECTOR).send_keys("PMP")
    driver.find_element(By.CSS_SELECTOR, LOGIN_BUTTON_SELECTOR).click()
    print("    Login submitted successfully.")

    print("\n>>> Step 2: Navigating to list and exporting...")
    
    print("    Hovering over 'Homeowners' menu...")
    homeowners_menu = WebDriverWait(driver, 30).until(
        EC.visibility_of_element_located((By.XPATH, "//*[normalize-space()='Homeowners']"))
    )
    actions = ActionChains(driver)
    actions.move_to_element(homeowners_menu)
    actions.perform()

    print("    Clicking 'Homeowner List' in submenu...")
    homeowner_list_submenu = WebDriverWait(driver, 10).until(
        EC.element_to_be_clickable((By.XPATH, "//*[normalize-space()='Homeowner List']"))
    )
    homeowner_list_submenu.click()

    print("    Waiting for 5 seconds for data to load...")
    time.sleep(5)
    print("    Data grid should now be loaded.")    

    print("    Clicking 'Export to Excel'...")
    export_button = WebDriverWait(driver, 20).until(
        EC.element_to_be_clickable((By.CSS_SELECTOR, "button.k-grid-excel"))
    )
    export_button.click()
    print("    Export command clicked. Waiting for download...")

    # --- Find, Convert, and Move the File ---
    downloaded_xlsx_path = None
    timeout = 60
    while timeout > 0:
        search_pattern = os.path.join(DOWNLOAD_DIR, "Homeowner Export*.xlsx")
        files = glob.glob(search_pattern)
        if files:
            latest_file = max(files, key=os.path.getctime)
            if (time.time() - os.path.getctime(latest_file)) < 120:
                downloaded_xlsx_path = latest_file
                break
        time.sleep(1)
        timeout -= 1

    if not downloaded_xlsx_path:
        raise Exception("Could not find a recently downloaded 'Homeowner Export' file.")

    print(f"    Found downloaded file: {os.path.basename(downloaded_xlsx_path)}")

    print("    Converting to CSV...")
    csv_path = os.path.join(DOWNLOAD_DIR, "ResidentImport.csv")
    excel_data = pd.read_excel(downloaded_xlsx_path)
    excel_data.to_csv(csv_path, index=False)
    print("    Conversion successful.")

    os.remove(downloaded_xlsx_path)

    print(f"    Moving {os.path.basename(csv_path)} to {NAS_PATH}...")
    shutil.move(csv_path, NAS_PATH)
    print("    File moved successfully.")

    print("\n>>> Step 3: Triggering import process on the server...")
    payload = {"file_path": SERVER_FILE_PATH, "dry_run": DRY_RUN}
    headers = {"Content-Type": "application/json", "X-API-KEY": API_KEY}
    response = requests.post(API_URL, json=payload, headers=headers, timeout=120)
    response.raise_for_status()
    print("    API call successful! Server response:")
    print(json.dumps(response.json(), indent=2))
    print("\n--- SCRIPT COMPLETED SUCCESSFULLY ---")

except Exception as e:
    print(f"\n--- AN ERROR OCCURRED ---")
    print(e)
    driver.save_screenshot("error_screenshot.png")
    print("    Screenshot 'error_screenshot.png' saved for debugging.")

finally:
    if 'driver' in locals():
        driver.quit()
    input("\nScript finished. Press Enter to exit.")
