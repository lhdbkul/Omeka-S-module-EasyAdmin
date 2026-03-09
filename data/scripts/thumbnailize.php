#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Thumbnail generation script using VIPS or ImageMagick.
 *
 * @copyright Daniel Berthereau 2025
 * @license Cecill 2.1
 *
 * Copied:
 * @see modules/EasyAdmin/data/scripts/thumbnailize.php
 * @see modules/Vips/data/scripts/thumbnailize.php
 */
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

/***************************************************
 * DEFAULT CONFIG
 ***************************************************/
$MAIN_DIR = 'files';
$ORIGINAL_DIR = "$MAIN_DIR/original";
$LARGE_DIR = "$MAIN_DIR/large";
$MEDIUM_DIR = "$MAIN_DIR/medium";
$SQUARE_DIR = "$MAIN_DIR/square";

$LARGE_SIZE = 800;
$MEDIUM_SIZE = 200;
$SQUARE_SIZE = 200;

$LOG_FILE = 'thumbnailize.log';
$MODE = 'missing';
$DRYRUN = false;
$PARALLEL = 1;
$PROGRESS = true;
$PDF_DPI = 150;
$CROP_MODE = 'centre';
$START_FROM = 0;
$SKIP_LARGE = false;
$SKIP_MEDIUM = false;
$SKIP_SQUARE = false;
$ERRORS = 0;

/***************************************************
 * PARSE CLI OPTIONS
 ***************************************************/
$options = getopt('', [
    'all', 'missing', 'parallel:', 'dry-run', 'log-file:', 'no-progress',
    'pdf-dpi:', 'crop-mode:', 'main-dir:', 'start-from:', 'skip-large',
    'skip-medium', 'skip-square', 'help',
]);

if (isset($options['help'])) {
    echo "Usage: php thumbnailize.php [OPTIONS]\n";
    echo "--all                Process all files (overwrite existing)\n";
    echo "--missing            Process only missing thumbnails (default)\n";
    echo "--parallel N         Run N parallel workers\n";
    echo "--dry-run            Show actions but do not run converters\n";
    echo "--log-file FILE      Set log file (default: thumbnailize.log)\n";
    echo "--no-progress        Disable progress bar\n";
    echo "--pdf-dpi N          Set dpi for pdf rendering\n";
    echo "--crop-mode MODE     Smart crop mode: centre (default), face, entropy, attention, document\n";
    echo "--main-dir DIR       Main directory (default: files/)\n";
    echo "--start-from N       Skip first N files in the list (resume from file N+1)\n";
    echo "--skip-large         Do not generate large thumbnails\n";
    echo "--skip-medium        Do not generate medium thumbnails\n";
    echo "--skip-square        Do not generate square thumbnails\n";
    exit(0);
}

if (isset($options['all'])) {
    $MODE = 'all';
}
if (isset($options['missing'])) {
    $MODE = 'missing';
}
if (isset($options['parallel'])) {
    $PARALLEL = max(1, (int) $options['parallel']);
}
if (isset($options['dry-run'])) {
    $DRYRUN = true;
}
if (isset($options['log-file'])) {
    $LOG_FILE = $options['log-file'];
}
if (isset($options['no-progress'])) {
    $PROGRESS = false;
}
if (isset($options['pdf-dpi'])) {
    $PDF_DPI = (int) $options['pdf-dpi'];
}
if (isset($options['crop-mode'])) {
    $CROP_MODE = $options['crop-mode'];
}
if (isset($options['main-dir'])) {
    $MAIN_DIR = rtrim($options['main-dir'], '/');
    $ORIGINAL_DIR = "$MAIN_DIR/original";
    $LARGE_DIR = "$MAIN_DIR/large";
    $MEDIUM_DIR = "$MAIN_DIR/medium";
    $SQUARE_DIR = "$MAIN_DIR/square";
}
if (isset($options['start-from'])) {
    $START_FROM = max(0, (int) $options['start-from']);
}
if (isset($options['skip-large'])) {
    $SKIP_LARGE = true;
}
if (isset($options['skip-medium'])) {
    $SKIP_MEDIUM = true;
}
if (isset($options['skip-square'])) {
    $SKIP_SQUARE = true;
}

/***************************************************
 * CHECK DEPENDENCIES
 ***************************************************/
$USE_VIPS = true;

$dummy = $ret = null;
exec('command -v vips', $dummy, $ret);
if ($ret !== 0) {
    echo "Warning: VIPS not found, using ImageMagick convert instead.\n";
    $USE_VIPS = false;
}

if (!$USE_VIPS) {
    exec('command -v convert', $dummy, $ret);
    if ($ret !== 0) {
        die("Error: Neither VIPS nor ImageMagick convert is installed.\n");
    }
}

if ($PARALLEL > 1) {
    if (!function_exists('pcntl_fork')) {
        die("pcntl extension is required for parallel processing.\n");
    }
}

/***************************************************
 * SETUP
 ***************************************************/
@mkdir($LARGE_DIR, 0777, true);
@mkdir($MEDIUM_DIR, 0777, true);
@mkdir($SQUARE_DIR, 0777, true);
touch($LOG_FILE);

$files = glob("$ORIGINAL_DIR/*.{jpg,jpeg,png,webp,tif,tiff,pdf,JPG,JPEG,PNG,WEBP,TIF,TIFF,PDF}", GLOB_BRACE);
sort($files);
$totalAll = count($files);

// Apply --start-from: skip the first N files.
if ($START_FROM > 0) {
    $files = array_slice($files, $START_FROM);
}

$total = count($files);

/***************************************************
 * HELPERS
 ***************************************************/
function logMsg(string $msg, string $file): void
{
    file_put_contents($file, $msg . "\n", FILE_APPEND);
}

function progressBar(int $count, int $total, int $errors = 0): void
{
    $width = 40;
    $percent = intval($count * 100 / max(1, $total));
    $filled = intval($width * $count / max(1, $total));
    $empty = $width - $filled;

    if ($errors > 0) {
        printf("\r[%s%s] %d%% (%d/%d, %d errors)",
            str_repeat('#', $filled),
            str_repeat('-', $empty),
            $percent, $count, $total, $errors
        );
    } else {
        printf("\r[%s%s] %d%% (%d/%d)",
            str_repeat('#', $filled),
            str_repeat('-', $empty),
            $percent, $count, $total
        );
    }
}

/***************************************************
 * TYPE DETECTION
 ***************************************************/
function detectType(string $file, bool $useVips): string
{
    if ($useVips) {
        $o = $ret = null;
        exec('vipsheader -f format ' . escapeshellarg($file), $o, $ret);
        if ($ret === 0 && !empty($o)) {
            return trim($o[0]);
        }
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'pdf') {
        return 'pdfload';
    }
    return 'image';
}

/***************************************************
 * RUN EXECS WITH FALLBACK
 ***************************************************/
function runVipsOrConvert(string $vipsCmd, string $convertCmd, bool $useVips): bool
{
    if ($useVips) {
        $o = $ret = null;
        exec($vipsCmd, $o, $ret);
        if ($ret === 0) {
            return true;
        }
    }
    $o = $ret = null;
    exec($convertCmd, $o, $ret);
    return $ret === 0;
}

/***************************************************
 * PROCESS ONE FILE
 ***************************************************/
function processFile(string $img, array $cfg): bool
{
    $base = basename($img);
    $filetype = detectType($img, $cfg['use_vips']);
    $hasError = false;

    if ($filetype === 'unknown') {
        logMsg("[skip]   Unknown: $base", $cfg['log_file']);
        return false;
    }

    $filename = pathinfo($base, PATHINFO_FILENAME) . '.jpg';
    $large_out = "{$cfg['large_dir']}/$filename";
    $medium_out = "{$cfg['medium_dir']}/$filename";
    $squareOut = "{$cfg['square_dir']}/$filename";

    if ($cfg['mode'] === 'missing') {
        $allExist = true;
        if (!$cfg['skip_large'] && !file_exists($large_out)) {
            $allExist = false;
        }
        if (!$cfg['skip_medium'] && !file_exists($medium_out)) {
            $allExist = false;
        }
        if (!$cfg['skip_square'] && !file_exists($squareOut)) {
            $allExist = false;
        }
        if ($allExist) {
            logMsg("[skip]   $base", $cfg['log_file']);
            return false;
        }
    }

    logMsg("[process] $base ($filetype)", $cfg['log_file']);

    $thumbSrc = $img;
    $tempPDF = null;

    /********************
     * PDF HANDLING
     ********************/
    if ($filetype === 'pdfload') {
        $tempPDF = tempnam(sys_get_temp_dir(), 'pdf') . '.jpg';

        if (!$cfg['dryrun']) {
            $vipsPDF = sprintf(
                "vips pdfload %s %s --page=0 --dpi=%d --n=1 --access=sequential --flatten --background '255 255 255'",
                escapeshellarg($img),
                escapeshellarg($tempPDF),
                $cfg['pdf_dpi']
            );

            $convertPDF = sprintf(
                'convert -density %d %s[0] -background white -flatten %s',
                $cfg['pdf_dpi'],
                escapeshellarg($img),
                escapeshellarg($tempPDF)
            );

            $o = $ret = null;
            if ($cfg['use_vips']) {
                exec($vipsPDF, $o, $ret);
            }
            if (!$cfg['use_vips'] || $ret !== 0) {
                $o = $ret = null;
                exec($convertPDF, $o, $ret);
                if ($ret !== 0) {
                    logMsg("[error]  $base: PDF conversion failed", $cfg['log_file']);
                    @unlink($tempPDF);
                    return true;
                }
            }
        }

        $thumbSrc = $tempPDF;
    }

    if (!$cfg['dryrun']) {

        /********************
         * LARGE
         ********************/
        if (!$cfg['skip_large']) {
            $thumbSize = $cfg['large_size'];

            $ok = runVipsOrConvert(
                'vips thumbnail '
                    . escapeshellarg($thumbSrc)
                    . ' ' . escapeshellarg($large_out)
                    . " $thumbSize --size=down",

                'convert '
                    . escapeshellarg($thumbSrc)
                    . " -auto-orient -resize {$thumbSize}x{$thumbSize}> "
                    . escapeshellarg($large_out),

                $cfg['use_vips']
            );
            if (!$ok) {
                logMsg("[error]  $base: large thumbnail failed", $cfg['log_file']);
                $hasError = true;
            }
        }

        /********************
         * MEDIUM
         ********************/
        if (!$cfg['skip_medium']) {
            $thumbSize = $cfg['medium_size'];

            $ok = runVipsOrConvert(
                'vips thumbnail '
                    . escapeshellarg($thumbSrc)
                    . ' ' . escapeshellarg($medium_out)
                    . " $thumbSize --size=down",

                'convert '
                    . escapeshellarg($thumbSrc)
                    . " -auto-orient -resize {$thumbSize}x{$thumbSize}> "
                    . escapeshellarg($medium_out),

                $cfg['use_vips']
            );
            if (!$ok) {
                logMsg("[error]  $base: medium thumbnail failed", $cfg['log_file']);
                $hasError = true;
            }
        }

        /********************
         * SQUARE
         ********************/
        if (!$cfg['skip_square']) {
            $thumbSize = $cfg['square_size'];

            // --- VIPS ---
            $vipsCmd =
            'vips thumbnail '
                . escapeshellarg($thumbSrc)
                . ' '
                . escapeshellarg($squareOut)
                . ' ' . $thumbSize
                . ' --height ' . $thumbSize
                . ' --size both'
                . ' --crop ' . escapeshellarg($cfg['crop_mode']);

            // --- Convert fallback ---
            // Convert has no entropy/attention modes like VIPS,
            // but it can be emulated using -gravity center.
            $convertCmd =
            'convert '
                . escapeshellarg($thumbSrc)
                . ' -auto-orient'
                . " -resize {$thumbSize}x{$thumbSize}^"
                . ' -gravity center'
                . " -extent {$thumbSize}x{$thumbSize} "
                . escapeshellarg($squareOut);

            $ok = runVipsOrConvert($vipsCmd, $convertCmd, $cfg['use_vips']);
            if (!$ok) {
                logMsg("[error]  $base: square thumbnail failed", $cfg['log_file']);
                $hasError = true;
            }
        }
    }

    if ($tempPDF && !$cfg['dryrun']) {
        @unlink($tempPDF);
    }

    return $hasError;
}

/***************************************************
 * CONFIG STRUCT FOR PASSING
 ***************************************************/
$config = [
    'mode' => $MODE,
    'dryrun' => $DRYRUN,
    'log_file' => $LOG_FILE,
    'large_dir' => $LARGE_DIR,
    'medium_dir' => $MEDIUM_DIR,
    'square_dir' => $SQUARE_DIR,
    'large_size' => $LARGE_SIZE,
    'medium_size' => $MEDIUM_SIZE,
    'square_size' => $SQUARE_SIZE,
    'pdf_dpi' => $PDF_DPI,
    'crop_mode' => $CROP_MODE,
    'use_vips' => $USE_VIPS,
    'skip_large' => $SKIP_LARGE,
    'skip_medium' => $SKIP_MEDIUM,
    'skip_square' => $SKIP_SQUARE,
];

/***************************************************
 * RUN
 ***************************************************/
echo "Mode: $MODE\n";
echo "Parallel jobs: $PARALLEL\n";
echo "Dry-run: " . ($DRYRUN ? 'true' : 'false') . "\n";
echo "Progress bar: " . ($PROGRESS ? 'true' : 'false') . "\n";
echo "PDF DPI: $PDF_DPI\n";
echo "Crop mode: $CROP_MODE\n";
echo "Log-file: $LOG_FILE\n";
echo "Main directory: $MAIN_DIR\n";
echo "Using VIPS: " . ($USE_VIPS ? 'true' : 'false') . "\n";
echo "Skip large: " . ($SKIP_LARGE ? 'true' : 'false') . "\n";
echo "Skip medium: " . ($SKIP_MEDIUM ? 'true' : 'false') . "\n";
echo "Skip square: " . ($SKIP_SQUARE ? 'true' : 'false') . "\n";
echo "Total files found: $totalAll\n";
if ($START_FROM > 0) {
    echo "Starting from file: $START_FROM\n";
}
echo "Files to process: $total\n";
echo "\nStarting…\nLogging to $LOG_FILE\n\n";

if ($PARALLEL > 1) {
    /**************
     * PARALLEL
     **************/

    $pool = [];
    $finished = 0;
    $status = null;

    foreach ($files as $img) {

        while (count($pool) >= $PARALLEL) {
            foreach ($pool as $key => $pid) {
                $done = pcntl_waitpid($pid, $status, WNOHANG);
                if ($done > 0) {
                    unset($pool[$key]);
                    $finished++;
                    if (pcntl_wexitstatus($status) !== 0) {
                        $ERRORS++;
                    }
                    if ($PROGRESS) {
                        progressBar($finished, $total, $ERRORS);
                    }
                }
            }
            usleep(50000);
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            die("Failed to fork.\n");
        }
        if ($pid === 0) {
            $err = processFile($img, $config);
            exit($err ? 1 : 0);
        }
        $pool[] = $pid;
    }

    while (!empty($pool)) {
        foreach ($pool as $key => $pid) {
            $done = pcntl_waitpid($pid, $status, WNOHANG);
            if ($done > 0) {
                unset($pool[$key]);
                $finished++;
                if (pcntl_wexitstatus($status) !== 0) {
                    $ERRORS++;
                }
                if ($PROGRESS) {
                    progressBar($finished, $total, $ERRORS);
                }
            }
        }
        usleep(50000);
    }

} else {
    /**************
     * SERIAL
     **************/

    $count = 0;
    foreach ($files as $img) {
        $err = processFile($img, $config);
        if ($err) {
            $ERRORS++;
        }
        $count++;
        if ($PROGRESS) {
            progressBar($count, $total, $ERRORS);
        }
    }
}

echo "\nDONE. Processed: $total files. Errors: $ERRORS.\n";
if ($ERRORS > 0) {
    echo "Check $LOG_FILE for [error] entries.\n";
}
