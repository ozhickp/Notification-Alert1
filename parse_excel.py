#!/usr/bin/env python3
"""
parse_excel.py

Mapping EKSAK kolom Excel (sheet: MASTER SCHEDULE) -> kolom database:
  DEPARTEMENT        -> department
  LINE               -> line
  OPERATION PROCESS  -> operation_process
  MACHINE NAME       -> machine_name
  PROCESS MACHINE    -> process_machine
  NAME UNIT          -> name_unit
  MAINTENANCE POINT  -> maintenance_point
  INTERVAL (MONTH)   -> interval_month
  USE DATE           -> use_date
  CHANGE DATE PLAN   -> change_date_plan
  REMINDER DAY       -> reminder_activity
  REMAINING MONTH    -> remaining_month
"""

import sys, json
import pandas as pd

COLUMN_MAP = {
    'DEPARTEMENT'       : 'department',
    'DEPARTMENT'        : 'department',
    'LINE'              : 'line',
    'OPERATION PROCESS' : 'operation_process',
    'MACHINE NAME'      : 'machine_name',
    'PROCESS MACHINE'   : 'process_machine',
    'NAME UNIT'         : 'name_unit',
    'MAINTENANCE POINT' : 'maintenance_point',
    'INTERVAL (MONTH)'  : 'interval_month',
    'USE DATE'          : 'use_date',
    'CHANGE DATE PLAN'  : 'change_date_plan',
    'REMINDER DAY'      : 'reminder_activity',
    'REMAINING MONTH'   : 'remaining_month',
}

DB_COLUMNS = list(dict.fromkeys(COLUMN_MAP.values()))

def safe(val):
    if val is None:
        return None
    s = str(val).strip()
    return None if s.lower() in ('nan', 'nat', 'none', '') else s

def fmt_date(val):
    try:
        return pd.to_datetime(val).strftime('%Y-%m-%d')
    except Exception:
        return None

def find_header_row(df_raw):
    for i, row in df_raw.iterrows():
        cells = [str(v).strip().upper() for v in row if pd.notna(v)]
        if any('DEPARTEMENT' in c or 'DEPARTMENT' in c for c in cells):
            return i
    return None

def find_data_start(df_raw, header_row_idx):
    raw_headers = [str(v).strip().upper() if pd.notna(v) else '' for v in df_raw.iloc[header_row_idx]]
    dept_col = next((ci for ci, hv in enumerate(raw_headers)
                     if 'DEPARTEMENT' in hv or 'DEPARTMENT' in hv), None)
    if dept_col is None:
        return header_row_idx + 1
    skip_vals = {'HARI INI', 'BULAN INI', 'TAHUN INI', 'LEBIH DARI 1 TAHUN'}
    for ri in range(header_row_idx + 1, len(df_raw)):
        val = safe(df_raw.iloc[ri, dept_col])
        if val and val.strip().upper() not in skip_vals:
            return ri
    return header_row_idx + 1

def main():
    if len(sys.argv) < 3:
        print(json.dumps({'status': 'error', 'message': 'Usage: parse_excel.py <input> <output_json>'}))
        sys.exit(1)

    file_path, output_path = sys.argv[1], sys.argv[2]

    try:
        xls = pd.ExcelFile(file_path)

        # Pilih sheet: utamakan MASTER SCHEDULE
        target_sheet = None
        for name in xls.sheet_names:
            if 'master' in name.lower():
                target_sheet = name
                break
        if not target_sheet:
            for name in xls.sheet_names:
                if 'prediktive' in name.lower() or 'schedule' in name.lower():
                    target_sheet = name
                    break
        if not target_sheet:
            target_sheet = xls.sheet_names[0]

        df_raw = pd.read_excel(file_path, sheet_name=target_sheet, header=None)

        header_row_idx = find_header_row(df_raw)
        if header_row_idx is None:
            with open(output_path, 'w') as f:
                json.dump({'status': 'error',
                           'message': 'Baris header tidak ditemukan. Pastikan ada kolom DEPARTEMENT.'}, f)
            sys.exit(0)

        data_start_idx = find_data_start(df_raw, header_row_idx)

        raw_headers  = df_raw.iloc[header_row_idx].tolist()
        norm_headers = [str(v).strip().upper() if pd.notna(v) else '' for v in raw_headers]

        df_data = df_raw.iloc[data_start_idx:].copy()
        df_data.columns = norm_headers
        df_data.reset_index(drop=True, inplace=True)

        rename_map = {}
        for col in norm_headers:
            if col in COLUMN_MAP and col not in rename_map:
                rename_map[col] = COLUMN_MAP[col]

        df_data.rename(columns=rename_map, inplace=True)

        required = ['department', 'change_date_plan']
        missing  = [r for r in required if r not in df_data.columns]
        if missing:
            avail = [c for c in df_data.columns if c]
            with open(output_path, 'w') as f:
                json.dump({'status': 'error',
                           'message': (f'Kolom wajib tidak ditemukan: {", ".join(missing)}. '
                                       f'Kolom tersedia: {", ".join(avail)}')}, f)
            sys.exit(0)

        for dc in ['use_date', 'change_date_plan']:
            if dc in df_data.columns:
                df_data[dc] = df_data[dc].apply(fmt_date)

        df_data = df_data[df_data['department'].apply(lambda v: safe(v) is not None)]

        records = []
        for _, row in df_data.iterrows():
            rec = {db_col: (safe(row[db_col]) if db_col in df_data.columns else None)
                   for db_col in DB_COLUMNS}
            records.append(rec)

        with open(output_path, 'w') as f:
            json.dump({'status': 'ok', 'total': len(records), 'rows': records}, f, default=str)

    except Exception as e:
        with open(output_path, 'w') as f:
            json.dump({'status': 'error', 'message': str(e)}, f)

if __name__ == '__main__':
    main()