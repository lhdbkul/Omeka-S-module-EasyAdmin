#!/usr/bin/env bash
[ -z "$BASH_VERSION" ] && exec bash "$0" "$@"

############################################
# Thumbnail generation script using VIPS or ImageMagick.
#
# Copyright Daniel Berthereau 2025
# Licence Cecill 2.1
#
# Copied:
# @see modules/EasyAdmin/data/scripts/thumbnailize.sh
# @see modules/Vips/data/scripts/thumbnailize.sh
############################################

set -euo pipefail

############################################
# DEFAULT CONFIGURATION
############################################
MAIN_DIR="files"
ORIGINAL_DIR="$MAIN_DIR/original"
LARGE_DIR="$MAIN_DIR/large"
MEDIUM_DIR="$MAIN_DIR/medium"
SQUARE_DIR="$MAIN_DIR/square"

LARGE_SIZE=800
MEDIUM_SIZE=200
SQUARE_SIZE=200

LOG_FILE="thumbnailize.log"
MODE="missing"
DRYRUN=false
PARALLEL=1
PROGRESS=true
PDF_DPI=150
CROP_MODE="centre"
START_FROM=0
SKIP_LARGE=false
SKIP_MEDIUM=false
SKIP_SQUARE=false
ERRORS=0

# Incremented files to manage progress and errors during parallel processes.
TMPCOUNT=$(mktemp /tmp/thumb_count_XXXXXX.tmp)
TMPERROR=$(mktemp /tmp/thumb_error_XXXXXX.tmp)
touch "$TMPCOUNT" "$TMPERROR"

############################################
# DEPENDENCY CHECKS
############################################
USE_VIPS=true

if ! command -v vips &>/dev/null; then
    echo "Warning: VIPS is not installed. Falling back to ImageMagick convert."
    USE_VIPS=false
fi

if ! $USE_VIPS; then
    if ! command -v convert &>/dev/null; then
        echo "Error: Neither vips nor convert found. Install at least one."
        exit 1
    fi
fi

# Only check GNU parallel if using parallel > 1.
if [[ "$PARALLEL" -gt 1 ]] && ! command -v parallel &>/dev/null; then
    echo "Error: GNU parallel not found but --parallel was used."
    exit 1
fi

############################################
# HELP
############################################
usage() {
    cat <<EOF
Usage: $0 [OPTIONS]

Options:
  --all                Process all files (overwrite existing)
  --missing            Process only missing thumbnails (default)
  --parallel N         Run N parallel jobs
  --dry-run            Show actions but do not run converters
  --log-file FILE      Set log file (default: thumbnailize.log)
  --no-progress        Disable progress bar
  --pdf-dpi N          Set dpi for pdf rendering
  --crop-mode MODE     Smart crop mode: centre (default), face, entropy, attention, document
  --main-dir DIR       Main directory (default: files/)
  --start-from N       Skip first N files in the list (resume from file N+1)
  --skip-large         Do not generate large thumbnails
  --skip-medium        Do not generate medium thumbnails
  --skip-square        Do not generate square thumbnails
  --help               Show help

Examples:

# Process all files in the original directory, creating thumbnails:
$0 --all

# Process only missing thumbnails (default behavior):
$0

# Use 4 parallel jobs for faster processing:
$0 --parallel 4

# Dry-run to see what would be done without writing files:
$0 --dry-run

# Specify pdf dpi and log file:
$0 --pdf-dpi 200 --log-file mylog.log

# Use smart face-aware cropping for square thumbnails:
$0 --crop-mode face

# Resume from file 198288:
$0 --start-from 198288

# Generate only square thumbnails (skip large and medium):
$0 --skip-large --skip-medium

# Combine options: parallel + all + dry-run + entropy crop:
$0 --all --parallel 8 --dry-run --crop-mode entropy
EOF
}

############################################
# PARSE ARGUMENTS
############################################
while [[ $# -gt 0 ]]; do
    case "$1" in
        --all) MODE="all" ;;
        --missing) MODE="missing" ;;
        --parallel) PARALLEL="$2"; shift ;;
        --dry-run) DRYRUN=true ;;
        --log-file) LOG_FILE="$2"; shift ;;
        --no-progress) PROGRESS=false ;;
        --pdf-dpi) PDF_DPI="$2"; shift ;;
        --crop-mode) CROP_MODE="$2"; shift ;;
        --main-dir) MAIN_DIR="$2"; shift ;;
        --start-from) START_FROM="$2"; shift ;;
        --skip-large) SKIP_LARGE=true ;;
        --skip-medium) SKIP_MEDIUM=true ;;
        --skip-square) SKIP_SQUARE=true ;;
        --help) usage; exit 0 ;;
        *) echo "Unknown option: $1"; usage; exit 1 ;;
    esac
    shift
done

# Update directories based on MAIN_DIR.
ORIGINAL_DIR="$MAIN_DIR/original"
LARGE_DIR="$MAIN_DIR/large"
MEDIUM_DIR="$MAIN_DIR/medium"
SQUARE_DIR="$MAIN_DIR/square"

############################################
# PREP
############################################
mkdir -p "$LARGE_DIR" "$MEDIUM_DIR" "$SQUARE_DIR"
touch "$LOG_FILE"
shopt -s nullglob

file_list=$(mktemp /tmp/thumb_files_XXXXXX.txt)
find "$ORIGINAL_DIR" -maxdepth 1 -type f \
    \( -iname "*.jpg" -o -iname "*.jpeg" \
    -o -iname "*.png" \
    -o -iname "*.webp" \
    -o -iname "*.tif" -o -iname "*.tiff" \
    -o -iname "*.pdf" \) \
    | sort \
    > "$file_list"

TOTAL_ALL=$(wc -l < "$file_list")

# Apply --start-from: skip the first N files.
if [[ "$START_FROM" -gt 0 ]]; then
    tail -n +"$((START_FROM + 1))" "$file_list" > "${file_list}.tmp"
    mv "${file_list}.tmp" "$file_list"
fi

TOTAL=$(wc -l < "$file_list")
COUNT=0

############################################
# TYPE DETECTION
############################################
detect_type() {
    local file="$1"
    if $USE_VIPS && vipsheader -f format "$file" &>/dev/null; then
        echo "$(vipsheader -f format "$file")"
        return
    fi
    [[ "$file" =~ \.pdf$|\.PDF$ ]] && echo "pdfload" && return
    echo "image"
}

############################################
# PROGRESS BAR
############################################
progress_bar() {
    [[ "$PROGRESS" == false ]] && return
    local width=40
    local current="$1"
    local total="${2:-$TOTAL}"
    local percent=$((100 * current / (total == 0 ? 1 : total)))
    local filled=$((width * current / (total == 0 ? 1 : total)))
    local empty=$((width - filled))
    local errors
    errors=$(wc -l < "$TMPERROR" 2>/dev/null || echo 0)

    printf "\r["
    printf "%0.s#" $(seq 1 $filled 2>/dev/null) 2>/dev/null
    printf "%0.s-" $(seq 1 $empty 2>/dev/null) 2>/dev/null
    if [[ "$errors" -gt 0 ]]; then
        printf "] %d%% (%d/%d, %d errors)" "$percent" "$current" "$total" "$errors"
    else
        printf "] %d%% (%d/%d)" "$percent" "$current" "$total"
    fi
}

############################################
# CONVERSION HELPERS (VIPS + fallback convert)
############################################
convert_large() {
    [[ "$SKIP_LARGE" == true ]] && return 0
    local in="$1" out="$2"

    if $USE_VIPS; then
        if vips thumbnail "$in" "$out" $LARGE_SIZE --size=down 2>/dev/null; then
            return 0
        fi
    fi

    convert "$in" -resize "${LARGE_SIZE}x${LARGE_SIZE}>" "$out" 2>/dev/null
}

convert_medium() {
    [[ "$SKIP_MEDIUM" == true ]] && return 0
    local in="$1" out="$2"

    if $USE_VIPS; then
        if vips thumbnail "$in" "$out" $MEDIUM_SIZE --size=down 2>/dev/null; then
            return 0
        fi
    fi

    convert "$in" -resize "${MEDIUM_SIZE}x${MEDIUM_SIZE}>" "$out" 2>/dev/null
}

convert_square() {
    [[ "$SKIP_SQUARE" == true ]] && return 0
    local in="$1" out="$2"

    # VIPS version
    if $USE_VIPS; then
        if vips thumbnail "$in" "$out" "${SQUARE_SIZE}" --height "${SQUARE_SIZE}" \
            --size both --crop "$CROP_MODE" 2>/dev/null; then
            return 0
        fi
    fi

    # ImageMagick fallback (resize first, then crop)
    convert "$in" \
        -resize "${SQUARE_SIZE}x${SQUARE_SIZE}^" \
        -gravity center \
        -extent "${SQUARE_SIZE}x${SQUARE_SIZE}" \
        "$out" 2>/dev/null
}

############################################
# PROCESS SINGLE FILE
############################################
process_file() {
    local img="$1"
    local base
    base=$(basename "$img")
    local filetype
    filetype=$(detect_type "$img")

    if [[ "$filetype" == "unknown" ]]; then
        echo "[skip]   Unknown: $base" >> "$LOG_FILE"
        echo 1 >> "$TMPCOUNT"
        return 0
    fi

    # Replace extension with .jpg.
    local filename="${base%.*}.jpg"
    local large_out="$LARGE_DIR/$filename"
    local medium_out="$MEDIUM_DIR/$filename"
    local square_out="$SQUARE_DIR/$filename"

    if [[ "$MODE" == "missing" ]]; then
        local all_exist=true
        if [[ "$SKIP_LARGE" != true ]] && [[ ! -f "$large_out" ]]; then
            all_exist=false
        fi
        if [[ "$SKIP_MEDIUM" != true ]] && [[ ! -f "$medium_out" ]]; then
            all_exist=false
        fi
        if [[ "$SKIP_SQUARE" != true ]] && [[ ! -f "$square_out" ]]; then
            all_exist=false
        fi
        if [[ "$all_exist" == true ]]; then
            echo "[skip]   $base" >> "$LOG_FILE"
            echo 1 >> "$TMPCOUNT"
            return 0
        fi
    fi

    echo "[process] $base ($filetype)" >> "$LOG_FILE"

    local thumbnail_input="$img"
    local temp_flattened=""
    local is_pdf=false

    # PDF handling.
    if [[ "$filetype" == "pdfload" ]]; then
        is_pdf=true
        temp_flattened=$(mktemp /tmp/pdf_flat_XXXXXX.jpg)

        if [[ "$DRYRUN" == false ]]; then
            if $USE_VIPS; then
                if ! vips pdfload "$img" "$temp_flattened" \
                    --page=0 \
                    --dpi=$PDF_DPI \
                    --n=1 \
                    --access=sequential \
                    --flatten \
                    --background "255 255 255" \
                    2>/dev/null; then
                    if ! convert -density "$PDF_DPI" "$img[0]" \
                        -background white -flatten "$temp_flattened" 2>/dev/null; then
                        echo "[error]  $base: PDF conversion failed" >> "$LOG_FILE"
                        echo 1 >> "$TMPERROR"
                        echo 1 >> "$TMPCOUNT"
                        rm -f "$temp_flattened"
                        return 0
                    fi
                fi
            else
                if ! convert -density "$PDF_DPI" "$img[0]" \
                    -background white \
                    -flatten "$temp_flattened" 2>/dev/null; then
                    echo "[error]  $base: PDF conversion failed" >> "$LOG_FILE"
                    echo 1 >> "$TMPERROR"
                    echo 1 >> "$TMPCOUNT"
                    rm -f "$temp_flattened"
                    return 0
                fi
            fi
        fi

        thumbnail_input="$temp_flattened"
    fi

    # Create outputs.
    if [[ "$DRYRUN" == false ]]; then
        if ! convert_large "$thumbnail_input" "$large_out"; then
            echo "[error]  $base: large conversion failed" >> "$LOG_FILE"
        fi
        if ! convert_medium "$thumbnail_input" "$medium_out"; then
            echo "[error]  $base: medium conversion failed" >> "$LOG_FILE"
        fi
        if ! convert_square "$thumbnail_input" "$square_out"; then
            echo "[error]  $base: square conversion failed" >> "$LOG_FILE"
            echo 1 >> "$TMPERROR"
        fi
    fi

    [[ "$is_pdf" == true && "$DRYRUN" == false ]] && rm -f "$temp_flattened"

    echo 1 >> "$TMPCOUNT"
    return 0
}

############################################
# EXPORT FOR PARALLEL
############################################
export -f process_file detect_type progress_bar \
       convert_large convert_medium convert_square
export MODE DRYRUN LOG_FILE LARGE_DIR MEDIUM_DIR SQUARE_DIR \
       LARGE_SIZE MEDIUM_SIZE SQUARE_SIZE PDF_DPI CROP_MODE \
       TMPCOUNT TMPERROR USE_VIPS SKIP_LARGE SKIP_MEDIUM SKIP_SQUARE

############################################
# EXECUTION
############################################
echo "Mode: $MODE"
echo "Parallel jobs: $PARALLEL"
echo "Dry-run: $DRYRUN"
echo "Progress bar: $PROGRESS"
echo "PDF DPI: $PDF_DPI"
echo "Crop mode: $CROP_MODE"
echo "Log-file: $LOG_FILE"
echo "Main directory: $MAIN_DIR"
echo "Using VIPS: $USE_VIPS"
echo "Skip large: $SKIP_LARGE"
echo "Skip medium: $SKIP_MEDIUM"
echo "Skip square: $SKIP_SQUARE"
echo "Total files found: $TOTAL_ALL"
if [[ "$START_FROM" -gt 0 ]]; then
    echo "Starting from file: $START_FROM"
fi
echo "Files to process: $TOTAL"
echo

run_serial() {
    while read -r img; do
        process_file "$img"
        COUNT=$(wc -l < "$TMPCOUNT")
        progress_bar "$COUNT" "$TOTAL"
    done < "$file_list"
}

############################################
# RUN PARALLEL
############################################
run_parallel() {
    # Run GNU parallel, allowing individual job failures.
    parallel --will-cite -j "$PARALLEL" --arg-file "$file_list" process_file &
    PARALLEL_PID=$!

    # Progress monitor: check every 0.5s until parallel finishes.
    while kill -0 "$PARALLEL_PID" 2>/dev/null; do
        COUNT=$(wc -l < "$TMPCOUNT" 2>/dev/null || echo 0)
        progress_bar "$COUNT" "$TOTAL"
        sleep 0.5
    done

    wait "$PARALLEL_PID" || true

    # Final progress update.
    COUNT=$(wc -l < "$TMPCOUNT" 2>/dev/null || echo 0)
    progress_bar "$COUNT" "$TOTAL"
}

############################################
# EXECUTION
############################################
echo "Starting…"
echo "Logging to $LOG_FILE"
echo

if [[ "$PARALLEL" -gt 1 ]]; then
    run_parallel
else
    run_serial
fi

ERRORS=$(wc -l < "$TMPERROR" 2>/dev/null || echo 0)

echo
echo "DONE. Processed: $(wc -l < "$TMPCOUNT") files. Errors: $ERRORS."
if [[ "$ERRORS" -gt 0 ]]; then
    echo "Check $LOG_FILE for [error] entries."
fi

# Cleanup.
rm -f "$TMPCOUNT" "$TMPERROR" "$file_list"
