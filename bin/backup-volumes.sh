#!/bin/bash

# Check if backup directory is provided
if [ -z "$1" ]; then
    echo "Usage: $0 <backup_directory> [volume1 volume2 ...]"
    exit 1
fi

# docker -v reads a relative path as a named volume, so make it absolute.
BACKUP_DIR="$(mkdir -p "$1" && cd "$1" && pwd)"
shift  # Remove the first argument (backup directory)

# Create backup directory if it doesn't exist
mkdir -p "$BACKUP_DIR"

# Get the list of volumes to back up
if [ "$#" -eq 0 ]; then
    VOLUMES=$(docker volume ls -q)  # Backup all volumes if none are specified
else
    VOLUMES="$@"
fi

# Backup each volume
for VOLUME in $VOLUMES; do
    BACKUP_FILE="$BACKUP_DIR/${VOLUME}.tar.gz"
    echo "Backing up volume: $VOLUME to $BACKUP_FILE"
    docker run --rm -v "${VOLUME}:/data" -v "${BACKUP_DIR}:/backup" busybox tar czf "/backup/${VOLUME}.tar.gz" -C /data .
    if [ $? -eq 0 ]; then
        echo "Backup of $VOLUME completed successfully."
    else
        echo "Failed to backup volume: $VOLUME"
    fi
    echo "----------------------------------------"
  
done

echo "All backups completed."
