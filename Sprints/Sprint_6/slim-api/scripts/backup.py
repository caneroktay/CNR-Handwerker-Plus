#!/usr/bin/env python3
# -*- coding: utf-8 -*-

# ============================================================
#  HandwerkerPro — Datenbank-Backup-System (Python)
#  Aufgaben: SQL-Dump erstellen, Komprimieren, Rotation
# ============================================================

import os
import time
import subprocess

# --- KONFIGURATION ---
DB_USER = "root"
DB_PASS = "DeinPasswortHier" 
DB_NAME = "handwerkerpro_db"
BACKUP_DIR = os.path.join(os.path.dirname(__file__), '..', 'backups_storage')
RETENTION_DAYS = 7

if not os.path.exists(BACKUP_DIR):
    os.makedirs(BACKUP_DIR)

# Zeitstempel und Dateiname direkt mit .gz Endung
timestamp = time.strftime('%Y%m%d-%H%M%S')
filename = f"backup_{DB_NAME}_{timestamp}.sql.gz"
filepath = os.path.join(BACKUP_DIR, filename)

print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] Backup-Prozess gestartet...")

try:
    # Passwort sicher übergeben
    env = os.environ.copy()
    env["MYSQL_PWD"] = DB_PASS
    
    # Wir nutzen eine Pipe: mysqldump | gzip > backup.sql.gz
    # Das ist effizienter als erst zu schreiben und dann zu packen.
    with open(filepath, "wb") as f:
        # Prozess 1: Dump erstellen
        p1 = subprocess.Popen(["mysqldump", "-u", DB_USER, DB_NAME], env=env, stdout=subprocess.PIPE)
        # Prozess 2: Den Output von p1 komprimieren
        p2 = subprocess.Popen(["gzip"], stdin=p1.stdout, stdout=f)
        
        # Erlaubt p1 eine SIGPIPE zu erhalten, wenn p2 abbricht
        p1.stdout.close() 
        p2.communicate()

    if p2.returncode == 0:
        print(f">> Backup erfolgreich erstellt und komprimiert: {filename}")
    else:
        raise Exception(f"Fehler bei der Kompression (Code: {p2.returncode})")

    # --- Rotation ---
    now = time.time()
    for f in os.listdir(BACKUP_DIR):
        f_path = os.path.join(BACKUP_DIR, f)
        if os.stat(f_path).st_mtime < now - (RETENTION_DAYS * 86400):
            if os.path.isfile(f_path):
                os.remove(f_path)
                print(f">> Altes Backup gelöscht: {f}")

    print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] Backup-Prozess beendet.")

except Exception as e:
    print(f"!!! FEHLER: {str(e)}")