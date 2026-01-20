import mysql.connector
from mysql.connector import Error
try:
    from fuzzywuzzy import fuzz
except ImportError:
    print("Please install fuzzywuzzy: pip install fuzzywuzzy")
    exit(1)
import logging
import os
import time
from datetime import datetime

# Get the current directory of the script
current_directory = os.path.dirname(os.path.abspath(__file__))

# Define the Logs directory path
logs_directory = os.path.join(current_directory, 'Logs')

# Ensure the Logs directory exists
os.makedirs(logs_directory, exist_ok=True)

# Define the full path for the log file
log_file_path = os.path.join(logs_directory, 'duplicate_checker.log')

# Configure logging
logging.basicConfig(
    filename=log_file_path,
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)

# Function to connect to MySQL database
def create_connection():
    try:
        connection = mysql.connector.connect(
            host='localhost',
            database='mdc',
            user='root',
            password='',
            connection_timeout=300
        )
        if connection.is_connected():
            logging.info("Successfully connected to MySQL database")
            return connection
    except Error as e:
        logging.error(f"Failed to connect to database: {e}")
        return None

# Function to create the duplicates table
def create_duplicates_table(connection):
    try:
        cursor = connection.cursor()
        cursor.execute("SHOW TABLES LIKE 'duplicates'")
        result = cursor.fetchone()

        if result:
            logging.info("Table 'duplicates' already exists")
        else:
            create_table_query = '''
            CREATE TABLE duplicates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                target_name VARCHAR(255),
                similar_name VARCHAR(255),
                similarity FLOAT,
                detected_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
            '''
            cursor.execute(create_table_query)
            connection.commit()
            logging.info("Table 'duplicates' created successfully")
        return True
    except Error as e:
        logging.error(f"Error creating duplicates table: {e}")
        return False

# Function to analyze differences between two strings
def analyze_differences(target_name, similar_name):
    differences = []
    # Check for spaces
    target_has_space = ' ' in target_name
    similar_has_space = ' ' in similar_name
    if target_has_space != similar_has_space:
        differences.append("Space difference detected")
    
    # Check for special characters
    special_chars = set('!@#$%^&*()-_=+[]{}|;:,.<>?/~`')
    target_special = any(char in special_chars for char in target_name)
    similar_special = any(char in special_chars for char in similar_name)
    if target_special != similar_special:
        differences.append("Special character difference detected")
    
    # Character-by-character comparison
    if len(target_name) == len(similar_name):
        char_diffs = [i for i in range(len(target_name)) if target_name[i] != similar_name[i]]
        if char_diffs:
            differences.append(f"Character differences at positions: {char_diffs}")
    
    # Length difference
    if len(target_name) != len(similar_name):
        differences.append(f"Length difference: {len(target_name)} vs {len(similar_name)}")
    
    return differences if differences else ["No specific differences identified"]

# Function to check for lookalike duplicates for a single target_name
def check_for_lookalikes(cursor, target_name, all_names, start_index):
    try:
        duplicates_found = []
        for i, name in enumerate(all_names[start_index:], start=start_index):
            if name != target_name:  # Skip self-comparison
                similarity = fuzz.ratio(target_name, name)
                if similarity > 85:  # Updated threshold to 85%
                    differences = analyze_differences(target_name, name)
                    logging.info(f"Duplicate found: '{target_name}' similar to '{name}' ({similarity}%). Differences: {', '.join(differences)}")
                    duplicates_found.append((name, similarity, differences))
        return duplicates_found
    except Error as e:
        logging.error(f"Error checking for duplicates: {e}")
        return []

# Function to insert duplicate record
def insert_duplicate(cursor, target_name, similar_name, similarity):
    try:
        insert_query = '''
        INSERT INTO duplicates (target_name, similar_name, similarity)
        VALUES (%s, %s, %s)
        '''
        cursor.execute(insert_query, (target_name, similar_name, similarity))
        logging.info(f"Inserted duplicate: '{target_name}' similar to '{similar_name}' ({similarity}%)")
        return True
    except Error as e:
        logging.error(f"Error inserting duplicate: {e}")
        return False

# Function to clean up duplicates
def cleanup_duplicates(connection):
    try:
        cursor = connection.cursor()
        delete_query = '''
        DELETE FROM duplicates
        WHERE target_name NOT IN (SELECT target_name FROM devices)
        '''
        cursor.execute(delete_query)
        connection.commit()
        logging.info("Cleaned up outdated duplicates")
        return True
    except Error as e:
        logging.error(f"Error cleaning up duplicates: {e}")
        return False

# Function to check all target_names for duplicates
def check_all_duplicates():
    connection = create_connection()
    if not connection or not connection.is_connected():
        logging.error("Database connection failed")
        print("Database connection failed. Check logs for details.")
        return False

    try:
        if not create_duplicates_table(connection):
            print("Failed to create duplicates table")
            return False

        cursor = connection.cursor()
        cursor.execute("SELECT target_name FROM devices")
        all_names = [row[0] for row in cursor.fetchall()]
        if not all_names:
            logging.warning("No records found in 'devices' table")
            print("No records found in 'devices' table")
            return False

        print(f"Found {len(all_names)} target_name entries in devices table")
        duplicate_count = 0

        # Clear existing duplicates to avoid redundancy
        cleanup_duplicates(connection)

        # Compare each target_name with others
        for i, target_name in enumerate(all_names):
            duplicates = check_for_lookalikes(cursor, target_name, all_names, i + 1)
            for similar_name, similarity, differences in duplicates:
                if insert_duplicate(cursor, target_name, similar_name, similarity):
                    duplicate_count += 1
                    print(f"Duplicate found and stored: '{target_name}' similar to '{similar_name}' ({similarity}%). Differences: {', '.join(differences)}")

        connection.commit()
        if duplicate_count > 0:
            print(f"Found and stored {duplicate_count} duplicate(s) at {datetime.now().strftime('%Y-%m-%d %I:%M:%S %p')}")
        else:
            print(f"No duplicates found in devices table at {datetime.now().strftime('%Y-%m-%d %I:%M:%S %p')}")
        return True

    except Error as e:
        logging.error(f"Error in duplicate checking process: {e}")
        print(f"Error occurred: {e}")
        return False
    finally:
        if connection.is_connected():
            connection.close()
            logging.info("MySQL connection closed")

# Main function to run duplicate checking in an indefinite loop
def main():
    CHECK_INTERVAL = 600  # Seconds between checks (10 minutes)
    while True:
        try:
            print(f"Starting duplicate check at {datetime.now().strftime('%Y-%m-%d %I:%M:%S %p')}")
            check_all_duplicates()
            print(f"Next check in {CHECK_INTERVAL} seconds...")
            time.sleep(CHECK_INTERVAL)
        except KeyboardInterrupt:
            print("Duplicate checker stopped by user")
            logging.info("Duplicate checker stopped by user")
            break
        except Exception as e:
            logging.error(f"Unexpected error in loop: {e}")
            print(f"Unexpected error: {e}. Retrying in {CHECK_INTERVAL} seconds...")
            time.sleep(CHECK_INTERVAL)

if __name__ == "__main__":
    main()
