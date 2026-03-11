#!/usr/bin/env python3
# -*- coding: utf-8 -*-

# ============================================================
#  HandwerkerPro — Datenbank-Backup-System (Python)
#  Aufgaben: SQL-Dump erstellen, Komprimieren, Rotation
# ============================================================

import os
import time
import subprocess
import shutil

# --- KONFIGURATION ---
DB_USER = "root"
DB_PASS = ""  # Falls ein Passwort gesetzt ist, hier eintragen
DB_NAME = "handwerkerpro_db"
BACKUP_DIR = os.path.join(os.path.dirname(__file__), '..', 'backups_storage')
RETENTION_DAYS = 7  # Wie viele Tage sollen Backups behalten werden?

# Verzeichnis erstellen, falls nicht vorhanden
if not os.path.exists(BACKUP_DIR):
    os.makedirs(BACKUP_DIR)

# Zeitstempel für den Dateinamen
timestamp = time.strftime('%Y%m%d-%H%M%S')
filename = f"backup_{DB_NAME}_{timestamp}.sql"
filepath = os.path.join(BACKUP_DIR, filename)

print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] Backup-Prozess gestartet...")

try:
    # --- SCHRITT 1: Datenbank-Dump erstellen ---
    # Befehl: mysqldump -u [user] [db_name] > [pfad]
    dump_cmd = f"mysqldump -u {DB_USER} {DB_NAME} > {filepath}"
    
    # Ausführung des Befehls
    subprocess.run(dump_cmd, shell=True, check=True)
    print(f">> SQL-Dump erfolgreich erstellt: {filename}")

    # --- SCHRITT 2: Datei komprimieren (GZIP) ---
    # Dies spart ca. 80-90% Speicherplatz
    gzip_cmd = f"gzip {filepath}"
    subprocess.run(gzip_cmd, shell=True, check=True)
    print(f">> Backup wurde komprimiert (.gz)")

    # --- SCHRITT 3: Alte Backups löschen (Rotation) ---
    now = time.time()
    deleted_count = 0
    
    for f in os.listdir(BACKUP_DIR):
        f_path = os.path.join(BACKUP_DIR, f)
        
        # Prüfen, ob die Datei älter als RETENTION_DAYS ist
        if os.stat(f_path).st_mtime < now - (RETENTION_DAYS * 86400):
            if os.path.isfile(f_path):
                os.remove(f_path)
                print(f">> Altes Backup gelöscht: {f}")
                deleted_count += 1

    print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] Backup-Prozess erfolgreich beendet.")
    print(f">> Rotation: {deleted_count} alte Datei(en) entfernt.")

except subprocess.CalledProcessError as e:
    print(f"!!! KRITISCHER FEHLER: Datenbank-Dump fehlgeschlagen. Prüfen Sie die MySQL-Anmeldedaten.")
except Exception as e:
    print(f"!!! UNERWARTETER FEHLER: {str(e)}")