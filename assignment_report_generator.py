#!/usr/bin/env python3
import mysql.connector
from mysql.connector import Error
import pandas as pd
import math
from datetime import datetime
import logging
import os
import sys
import json

# Setup logging
current_directory = os.path.dirname(os.path.abspath(__file__))
logs_directory = os.path.join(current_directory, 'Logs')
os.makedirs(logs_directory, exist_ok=True)
log_file_path = os.path.join(logs_directory, 'vehicle_info_report.log')

logging.basicConfig(
    filename=log_file_path,
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)

def log_message(message):
    """Log message only (no print to avoid corrupting output)"""
    logging.info(message)

def create_connection():
    """Create database connection"""
    try:
        connection = mysql.connector.connect(
            host='localhost',
            database='mdc',
            user='root',
            password='',
            connection_timeout=300
        )
        if connection.is_connected():
            log_message("Connected to MySQL database")
            return connection
    except Error as e:
        log_message(f"Error connecting to database: {e}")
        return None

def haversine(coord1, coord2):
    """
    Calculate the great circle distance between two points 
    on the earth (specified in decimal degrees)
    Returns distance in kilometers
    """
    R = 6371.0  # Radius of the Earth in kilometers
    lat1, lon1 = coord1
    lat2, lon2 = coord2
    
    lat1_rad = math.radians(lat1)
    lon1_rad = math.radians(lon1)
    lat2_rad = math.radians(lat2)
    lon2_rad = math.radians(lon2)
    
    dlon = lon2_rad - lon1_rad
    dlat = lat2_rad - lat1_rad
    
    a = math.sin(dlat / 2)**2 + math.cos(lat1_rad) * math.cos(lat2_rad) * math.sin(dlon / 2)**2
    c = 2 * math.asin(math.sqrt(a))
    
    return R * c

def is_within_geofence(device_location, geofence_coordinates, radius=5):
    """Check if device is within geofence radius (default 5km)"""
    distance = haversine(device_location, geofence_coordinates)
    return distance <= radius

def get_assignment_tables(connection):
    """Get all tables that start with 'assignment_'"""
    try:
        cursor = connection.cursor()
        query = """
        SELECT table_name 
        FROM information_schema.tables 
        WHERE table_schema = 'mdc'
        AND table_name LIKE 'assignment_%'
        """
        cursor.execute(query)
        tables = [table[0] for table in cursor.fetchall()]
        log_message(f"Found {len(tables)} assignment tables")
        return tables
    except Error as e:
        log_message(f"Error getting assignment tables: {e}")
        return []

def get_assignment_coordinates(connection, table_name):
    """Get coordinates and site names from assignment table"""
    try:
        cursor = connection.cursor()
        query = f"SELECT site, coordinates, location FROM {table_name}"
        cursor.execute(query)
        results = cursor.fetchall()
        
        coordinates_data = []
        for row in results:
            site = row[0]
            coords_str = row[1].strip()
            location = row[2] if row[2] else ""
            
            try:
                # Parse coordinates (format: "lat, lon" or "lat,lon")
                coords_parts = coords_str.replace('\n', '').split(',')
                if len(coords_parts) == 2:
                    lat = float(coords_parts[0].strip())
                    lon = float(coords_parts[1].strip())
                    coordinates_data.append({
                        'site': site,
                        'lat': lat,
                        'lon': lon,
                        'location': location
                    })
            except (ValueError, IndexError) as e:
                log_message(f"Invalid coordinate format in {table_name} for site {site}: {coords_str}")
                continue
        
        return coordinates_data
    except Error as e:
        log_message(f"Error getting coordinates from {table_name}: {e}")
        return []

def get_devices_data(connection):
    """Get all devices from devices table with necessary columns including tag, status, remarks, days_no_gps, and last_gps_assignment"""
    try:
        cursor = connection.cursor(dictionary=True)
        query = """
        SELECT 
            target_name,
            latitude,
            longitude,
            address,
            cut_address,
            equipment_type,
            assignment as current_assignment,
            tag,
            physical_status,
            remarks,
            days_no_gps,
            last_gps_assignment
        FROM devices
        WHERE latitude IS NOT NULL 
        AND longitude IS NOT NULL
        AND latitude != 0 
        AND longitude != 0
        """
        cursor.execute(query)
        devices = cursor.fetchall()
        log_message(f"Retrieved {len(devices)} devices from database")
        return devices
    except Error as e:
        log_message(f"Error getting devices data: {e}")
        return []

def update_last_gps_assignment(connection, target_name, current_assignment, suggested_assignment):
    """
    Update last_gps_assignment in database when assignment changes
    Format: "Assignment Name MM/DD/YY"
    Returns the formatted string for display
    """
    try:
        # Get current date in MM/DD/YY format
        current_date = datetime.now().strftime('%m/%d/%y')
        
        # Format: "Assignment Name MM/DD/YY"
        last_assignment_entry = f"{current_assignment} {current_date}"
        
        # Update the database
        cursor = connection.cursor()
        update_query = """
        UPDATE devices 
        SET last_gps_assignment = %s 
        WHERE target_name = %s
        """
        cursor.execute(update_query, (last_assignment_entry, target_name))
        connection.commit()
        
        log_message(f"  Updated last_gps_assignment for {target_name}: {last_assignment_entry}")
        return last_assignment_entry
        
    except Error as e:
        log_message(f"  Error updating last_gps_assignment for {target_name}: {e}")
        return None

def extract_short_location(address):
    """Extract a short location from full address (last 2-3 components)"""
    if not address:
        return "Unknown Location"
    
    # Split by comma and get relevant parts
    parts = [p.strip() for p in address.split(',')]
    
    # Try to get province/region (usually last 2-3 parts)
    if len(parts) >= 2:
        # Get last 2 parts (typically: Province, Region)
        return ', '.join(parts[-2:])
    
    return address
    """Extract a short location from full address (last 2-3 components)"""
    if not address:
        return "Unknown Location"
    
    # Split by comma and get relevant parts
    parts = [p.strip() for p in address.split(',')]
    
    # Try to get province/region (usually last 2-3 parts)
    if len(parts) >= 2:
        # Get last 2 parts (typically: Province, Region)
        return ', '.join(parts[-2:])
    
    return address
    """Extract a short location from full address (last 2-3 components)"""
    if not address:
        return "Unknown Location"
    
    # Split by comma and get relevant parts
    parts = [p.strip() for p in address.split(',')]
    
    # Try to get province/region (usually last 2-3 parts)
    if len(parts) >= 2:
        # Get last 2 parts (typically: Province, Region)
        return ', '.join(parts[-2:])
    
    return address

def find_nearest_assignment(device, assignment_tables, connection):
    """
    Find the nearest assignment geofence for a device
    Returns assignment name if within 5km geofence, else returns "CURRENT LOC: short location"
    """
    device_location = (device['latitude'], device['longitude'])
    nearest_assignment = None
    nearest_distance = float('inf')
    nearest_site = None
    
    # Assignment name mapping
    assignment_map = {
        'assignment_amlan': '0528 - Amlan',
        'assignment_balingueo': '0555 - Balingueo SS',
        'assignment_banilad': '0301 - Banilad SS',
        'assignment_barotac': '0547 - Barotac Viejo SS',
        'assignment_bayombong': '0555 - Bayombong SS',
        'assignment_binan': '0540 - Binan SS',
        'assignment_bolo': '0565 - Bolo',
        'assignment_botolan': '0569 - Botolan SS',
        'assignment_cadiz': '0370 - Cadiz SS',
        'assignment_calacass': '0313 - Calaca SS',
        'assignment_calacatl': '0311 - Calaca TL',
        'assignment_calatrava': '0370 - Calatrava SS',
        'assignment_castillejos': '0557 - Castillejos TL',
        'assignment_dasmarinas': '0540 - Dasmarinas SS',
        'assignment_dumanjug': '0352 - Dumanjug SS',
        'assignment_ebmagalona': '0370 - EB Magalona SS',
        'assignment_headoffice': 'Head Office',
        'assignment_hermosatl': '0559 - Hermosa TL',
        'assignment_hermosa': '0555 - Hermosa SS',
        'assignment_ilijan': '0589 - Ilijan SS',
        'assignment_isabel': '0511 - Isabel SS',
        'assignment_maasin': '0511 - Maasin SS',
        'assignment_muntinlupa': '0540 - Muntinlupa SS',
        'assignment_pantabangan': '0555 - Pantabangan SS',
        'assignment_paoay': 'Paoay TL',
        'assignment_pinamucan': '0546 - Pinamucan SS',
        'assignment_quirino': 'Quirino',
        'assignment_sanjose': '0512 - San Jose SS',
        'assignment_tabango': '0511 - Tabango SS',
        'assignment_tayabas': '0569 - Tayabas SS',
        'assignment_taytay': '0555 - Taytay SS',
        'assignment_terrasolar': 'Terra Solar',
        'assignment_tuguegarao': '0555 - Tuguegarao SS',
        'assignment_tuy': '0313 - Tuy SS'
    }
    
    for table_name in assignment_tables:
        coordinates_data = get_assignment_coordinates(connection, table_name)
        
        for coord_info in coordinates_data:
            geofence_location = (coord_info['lat'], coord_info['lon'])
            distance = haversine(device_location, geofence_location)
            within_fence = is_within_geofence(device_location, geofence_location, 5)  # 5km radius
            
            if within_fence and distance < nearest_distance:
                nearest_distance = distance
                nearest_assignment = assignment_map.get(table_name, table_name.replace('assignment_', '').title())
                nearest_site = coord_info['site']
    
    if nearest_assignment:
        return {
            'assignment': nearest_assignment,
            'distance': round(nearest_distance, 2),
            'site': nearest_site
        }
    else:
        # Return "CURRENT LOC: short location" if no assignment found
        short_location = extract_short_location(device.get('address', ''))
        return {
            'assignment': f"CURRENT LOC: {short_location}",
            'distance': None,
            'site': None
        }

def export_to_excel(devices_with_assignments, output_file):
    """Export data to Excel file with Equipment Type, Vehicle/Equipment, Tag, Assignment, Status, and Remarks columns"""
    try:
        from openpyxl.styles import Alignment, Font, Border, Side
        
        # Create DataFrame
        df = pd.DataFrame(devices_with_assignments)
        
        # Sort by Equipment Type, then by Vehicle Name
        df = df.sort_values(['equipment_type', 'target_name'])
        
        # Prepare data with Equipment Type in first column
        export_data = []
        current_equipment_type = None
        
        for _, row in df.iterrows():
            equipment_type = row['equipment_type']
            
            # Use suggested_assignment as the new current_assignment
            new_assignment = row['suggested_assignment']
            
            # Check if we need to add "CURRENT LOC:" prefix using cut_address
            if new_assignment.startswith("CURRENT LOC:"):
                new_assignment = f"CURRENT LOC: {row['cut_address']}"
            
            # Handle empty values - keep them empty instead of showing 'N/A'
            tag_value = row.get('tag', '')
            if tag_value is None or str(tag_value).strip() == '' or str(tag_value).lower() == 'none':
                tag_value = ''
            
            status_value = row.get('physical_status', '')
            if status_value is None or str(status_value).strip() == '' or str(status_value).lower() == 'none':
                status_value = ''
            
            # Check days_no_gps and append "(No GPS)" to status if >= 60 days
            days_no_gps = row.get('days_no_gps', 0)
            try:
                days_no_gps = int(days_no_gps) if days_no_gps is not None else 0
            except (ValueError, TypeError):
                days_no_gps = 0
            
            if days_no_gps >= 60:
                if status_value.strip():
                    status_value = f"{status_value} (No GPS)"
                else:
                    status_value = "(No GPS)"
            
            remarks_value = row.get('remarks', '')
            if remarks_value is None or str(remarks_value).strip() == '' or str(remarks_value).lower() == 'none':
                remarks_value = ''
            
            # Check if we should display last_gps_assignment
            last_gps_assignment = row.get('last_gps_assignment', '')
            if last_gps_assignment and str(last_gps_assignment).strip() and str(last_gps_assignment).lower() != 'none':
                last_assignment_text = f"Last Assignment: {last_gps_assignment}"
                if remarks_value.strip():
                    remarks_value = f"{remarks_value}, {last_assignment_text}"
                else:
                    remarks_value = last_assignment_text
            
            export_data.append({
                'Equipment Type': equipment_type if equipment_type != current_equipment_type else '',
                'Vehicle/Equipment Name': row['target_name'],
                'Tag': tag_value,
                'Assignment': new_assignment,
                'Status': status_value,
                'Remarks': remarks_value
            })
            
            current_equipment_type = equipment_type
        
        # Create new DataFrame with final structure
        final_df = pd.DataFrame(export_data)
        
        # Create Excel writer with formatting
        with pd.ExcelWriter(output_file, engine='openpyxl') as writer:
            # Write dataframe starting from row 3 to leave room for title and headers
            final_df.to_excel(writer, sheet_name='Vehicle Info Report', index=False, startrow=2, header=False)
            
            # Get workbook and worksheet objects
            workbook = writer.book
            worksheet = writer.sheets['Vehicle Info Report']
            
            # Add title row at the top with current date and time
            current_datetime = datetime.now().strftime('%B %d, %Y at %I:%M %p')
            worksheet.insert_rows(1)
            worksheet['A1'] = f'EQUIPMENTS / VEHICLES AS OF {current_datetime.upper()} based on GPS'
            worksheet.merge_cells('A1:F1')
            
            # Add sub-headers in row 2
            worksheet['A2'] = 'Equipment Type'
            worksheet['B2'] = 'Equipment/Vehicle'
            worksheet['C2'] = 'Tag'
            worksheet['D2'] = 'Assignment'
            worksheet['E2'] = 'Status'
            worksheet['F2'] = 'Remarks'
            
            # Style the title
            title_cell = worksheet['A1']
            title_cell.font = Font(bold=True, size=14)
            title_cell.alignment = Alignment(horizontal='center', vertical='center')
            
            # Style the sub-headers
            header_font = Font(bold=True)
            for cell in worksheet[2]:
                cell.font = header_font
                cell.alignment = Alignment(horizontal='center', vertical='center')
            
            # Set column widths
            worksheet.column_dimensions['A'].width = 25  # Equipment type
            worksheet.column_dimensions['B'].width = 35  # Vehicle name
            worksheet.column_dimensions['C'].width = 20  # Tag
            worksheet.column_dimensions['D'].width = 45  # Assignment
            worksheet.column_dimensions['E'].width = 15  # Status
            worksheet.column_dimensions['F'].width = 40  # Remarks
            
            # Merge cells for same equipment types and apply borders
            thin_border = Border(
                left=Side(style='thin'),
                right=Side(style='thin'),
                top=Side(style='thin'),
                bottom=Side(style='thin')
            )
            
            # Apply border to title cell
            title_cell.border = thin_border
            
            # Apply borders to header cells
            for col_idx in range(1, 7):
                worksheet.cell(row=2, column=col_idx).border = thin_border
            
            start_row = 3  # Data starts from row 3 now
            current_type = None
            
            for row_idx in range(3, len(final_df) + 3):
                cell_value = worksheet.cell(row=row_idx, column=1).value
                
                # Apply borders to all cells
                for col_idx in range(1, 7):
                    worksheet.cell(row=row_idx, column=col_idx).border = thin_border
                    worksheet.cell(row=row_idx, column=col_idx).alignment = Alignment(
                        vertical='center',
                        wrap_text=True
                    )
                
                if cell_value:  # New equipment type
                    if current_type is not None and start_row < row_idx:
                        # Merge previous group
                        worksheet.merge_cells(start_row=start_row, start_column=1, 
                                            end_row=row_idx-1, end_column=1)
                        worksheet.cell(row=start_row, column=1).alignment = Alignment(
                            horizontal='center', vertical='center'
                        )
                    
                    current_type = cell_value
                    start_row = row_idx
            
            # Merge last group
            if start_row < len(final_df) + 3:
                worksheet.merge_cells(start_row=start_row, start_column=1, 
                                    end_row=len(final_df)+2, end_column=1)
                worksheet.cell(row=start_row, column=1).alignment = Alignment(
                    horizontal='center', vertical='center'
                )
            
            # Freeze title and header rows
            worksheet.freeze_panes = 'A3'
        
        log_message(f"Successfully exported to {output_file}")
        return True
    except Exception as e:
        log_message(f"Error exporting to Excel: {e}")
        return False

def main():
    """Main function"""
    log_message("=" * 80)
    log_message("Starting Vehicle Info Report Generator")
    log_message("=" * 80)
    
    # Connect to database
    connection = create_connection()
    if not connection:
        log_message("Failed to connect to database. Exiting.")
        print(json.dumps({'success': False, 'error': 'Database connection failed'}))
        sys.exit(1)
    
    try:
        # Get assignment tables
        log_message("Step 1: Retrieving assignment tables...")
        assignment_tables = get_assignment_tables(connection)
        if not assignment_tables:
            log_message("No assignment tables found. Exiting.")
            print(json.dumps({'success': False, 'error': 'No assignment tables found'}))
            return
        
        # Get devices data
        log_message("Step 2: Retrieving devices data...")
        devices = get_devices_data(connection)
        if not devices:
            log_message("No devices found. Exiting.")
            print(json.dumps({'success': False, 'error': 'No devices found'}))
            return
        
        # Process each device
        log_message("Step 3: Processing devices and finding assignments...")
        devices_with_assignments = []
        
        # Assignment name mapping for current assignments
        assignment_map = {
            'assignment_amlan': '0528 - Amlan',
            'assignment_balingueo': '0555 - Balingueo SS',
            'assignment_banilad': '0301 - Banilad SS',
            'assignment_barotac': '0547 - Barotac Viejo SS',
            'assignment_bayombong': '0555 - Bayombong SS',
            'assignment_binan': '0540 - Binan SS',
            'assignment_bolo': '0565 - Bolo',
            'assignment_botolan': '0569 - Botolan SS',
            'assignment_cadiz': '0370 - Cadiz SS',
            'assignment_calacass': '0313 - Calaca SS',
            'assignment_calacatl': '0311 - Calaca TL',
            'assignment_calatrava': '0370 - Calatrava SS',
            'assignment_castillejos': '0557 - Castillejos TL',
            'assignment_dasmarinas': '0540 - Dasmarinas SS',
            'assignment_dumanjug': '0352 - Dumanjug SS',
            'assignment_ebmagalona': '0370 - EB Magalona SS',
            'assignment_headoffice': 'Head Office',
            'assignment_hermosatl': '0559 - Hermosa TL',
            'assignment_hermosa': '0555 - Hermosa SS',
            'assignment_ilijan': '0589 - Ilijan SS',
            'assignment_isabel': '0511 - Isabel SS',
            'assignment_maasin': '0511 - Maasin SS',
            'assignment_muntinlupa': '0540 - Muntinlupa SS',
            'assignment_pantabangan': '0555 - Pantabangan SS',
            'assignment_paoay': 'Paoay TL',
            'assignment_pinamucan': '0546 - Pinamucan SS',
            'assignment_quirino': 'Quirino',
            'assignment_sanjose': '0512 - San Jose SS',
            'assignment_tabango': '0511 - Tabango SS',
            'assignment_tayabas': '0569 - Tayabas SS',
            'assignment_taytay': '0555 - Taytay SS',
            'assignment_terrasolar': 'Terra Solar',
            'assignment_tuguegarao': '0555 - Tuguegarao SS',
            'assignment_tuy': '0313 - Tuy SS'
        }
        
        for idx, device in enumerate(devices, 1):
            log_message(f"Processing {idx}/{len(devices)}: {device['target_name']}")
            
            # Skip GPS Trackers
            if device['equipment_type'] == "GPS Tracker":
                log_message(f"  Skipping GPS Tracker: {device['target_name']}")
                continue
            
            # Find nearest assignment
            assignment_info = find_nearest_assignment(
                device, assignment_tables, connection
            )
            
            # Map current assignment to friendly name
            current_assignment = device.get('current_assignment', '')
            current_assignment_display = assignment_map.get(current_assignment, current_assignment if current_assignment else 'N/A')
            
            suggested_assignment = assignment_info['assignment']
            
            # Check if assignment has changed
            assignment_changed = False
            last_gps_assignment = device.get('last_gps_assignment', '')
            
            # Parse last_gps_assignment to get the stored assignment name (without date)
            stored_last_assignment = ''
            if last_gps_assignment and str(last_gps_assignment).strip():
                # Format is "Assignment Name MM/DD/YY", extract just the assignment name
                parts = str(last_gps_assignment).rsplit(' ', 1)  # Split from the right to get date
                if len(parts) > 0:
                    stored_last_assignment = parts[0].strip()
            
            # Assignment has changed if:
            # 1. Suggested assignment is different from current assignment (and suggested is not CURRENT LOC)
            # 2. OR suggested assignment starts with "CURRENT LOC:" (assignment changed to unknown location)
            # 3. AND the stored last assignment is different from the current assignment (to update date)
            if current_assignment and suggested_assignment:
                # Normalize current assignment for comparison
                current_normalized = assignment_map.get(current_assignment, current_assignment)
                
                if suggested_assignment.startswith('CURRENT LOC:'):
                    # Assignment changed to current location (outside any geofence)
                    # Only update if we haven't recorded this change yet OR if stored assignment is different
                    if stored_last_assignment != current_normalized:
                        assignment_changed = True
                        log_message(f"  Assignment changed: {current_normalized} -> CURRENT LOC")
                elif current_normalized != suggested_assignment:
                    # Assignment changed to a different geofence
                    # Only update if we haven't recorded this change yet OR if stored assignment is different
                    if stored_last_assignment != current_normalized:
                        assignment_changed = True
                        log_message(f"  Assignment changed: {current_normalized} -> {suggested_assignment}")
            
            # Update last_gps_assignment if assignment has changed
            if assignment_changed and current_assignment:
                updated_last_assignment = update_last_gps_assignment(connection, device['target_name'], current_assignment_display, suggested_assignment)
                if updated_last_assignment:
                    last_gps_assignment = updated_last_assignment
            
            devices_with_assignments.append({
                'target_name': device['target_name'],
                'equipment_type': device['equipment_type'],
                'current_assignment': current_assignment_display,
                'suggested_assignment': suggested_assignment,
                'distance_km': assignment_info['distance'],
                'nearest_site': assignment_info['site'] if assignment_info['site'] else '',
                'address': device.get('address', ''),
                'cut_address': device.get('cut_address', ''),
                'tag': device.get('tag'),
                'physical_status': device.get('physical_status'),
                'remarks': device.get('remarks'),
                'days_no_gps': device.get('days_no_gps', 0),
                'last_gps_assignment': last_gps_assignment
            })
            
            log_message(f"  Suggested Assignment: {suggested_assignment}")
            if assignment_info['distance']:
                log_message(f"  Distance: {assignment_info['distance']} km")
            
            # Log GPS status if no GPS
            days_no_gps = device.get('days_no_gps', 0)
            if days_no_gps and days_no_gps >= 60:
                log_message(f"  GPS Status: No GPS ({days_no_gps} days)")
        
        # Export to Excel
        log_message(f"Step 4: Exporting {len(devices_with_assignments)} records to Excel...")
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        output_file = os.path.join(current_directory, f'vehicle_info_report_{timestamp}.xlsx')
        
        if export_to_excel(devices_with_assignments, output_file):
            log_message(f"SUCCESS: Vehicle info report exported to {output_file}")
            log_message(f"Total records processed: {len(devices_with_assignments)}")
            # Output JSON response only
            print(json.dumps({
                'success': True,
                'file': output_file,
                'records': len(devices_with_assignments)
            }))
        else:
            log_message("Failed to export to Excel")
            print(json.dumps({'success': False, 'error': 'Failed to export to Excel'}))
            sys.exit(1)
    
    except Exception as e:
        log_message(f"Error in main process: {e}")
        print(json.dumps({'success': False, 'error': str(e)}))
        sys.exit(1)
    
    finally:
        if connection and connection.is_connected():
            connection.close()
            log_message("Database connection closed")
        log_message("Script completed")

if __name__ == "__main__":
    main()