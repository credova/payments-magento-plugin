#!/bin/bash

# Check if backup directory is provided
if [ -z "$1" ]; then
    echo "Usage: $0 <backup_directory> [volume1 volume2 ...]"
    exit 1
fi

# docker -v reads a relative path as a named volume, so make it absolute.
BACKUP_DIR="$(mkdir -p "$1" && cd "$1" && pwd)"
shift  # Remove the first argument (backup directory)

# Get the list of backup files
if [ "$#" -eq 0 ]; then
    BACKUPS=$(ls "$BACKUP_DIR"/*.tar.gz 2>/dev/null)
else
    BACKUPS=""
    for VOLUME in "$@"; do
        BACKUP_FILE="$BACKUP_DIR/${VOLUME}.tar.gz"
        if [ -f "$BACKUP_FILE" ]; then
            BACKUPS+="$BACKUP_FILE "
        else
            echo "Warning: Backup file not found for volume $VOLUME"
        fi
    done
fi

if [ -z "$BACKUPS" ]; then
    echo "No backup files found to restore."
    exit 1
fi

# Restore each volume
for BACKUP_FILE in $BACKUPS; do
    VOLUME=$(basename "$BACKUP_FILE" .tar.gz)
    echo "Restoring volume: $VOLUME from $BACKUP_FILE"
    docker volume create "$VOLUME" >/dev/null 2>&1
    docker run --rm -v "${VOLUME}:/data" -v "${BACKUP_DIR}:/backup" busybox tar xzf "/backup/${VOLUME}.tar.gz" -C /data
    if [ $? -eq 0 ]; then
        echo "Restore of $VOLUME completed successfully."
    else
        echo "Failed to restore volume: $VOLUME"
    fi
    echo "----------------------------------------"
done

echo "All restores completed."

