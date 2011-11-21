#!/usr/local/bin/php
<?php
/**
 * This script is designed to fix the file and directory permissions on a drupal 6 site
 * so that it will pass the security review module's check.
 */
$publicFileNames    = array('sitemap.xml', // these file names must be world-readable
                           'robots.txt',
                           '.htaccess');
$publicFileDirs     = array('files');      // files in these directories must be world-readable
$publicFileSuffixes = array('.css',        // files ending in these must be world-readable
                           '.js',
                           '.png',
                           '.jpg',
                           '.jpeg',
                           '.gif');
$publicDirs     = array('files');   // these dirs and sub-dirs within them must be world-readable

if ($argc === 1) {
    echo "\n";
    echo " USAGE: $argv[0] -s<startDir> [-q] [-n] [-v<1|2|3|4>]\n";
    echo "\n";
    echo " where startDir is the top level directory where drupal is installed.\n";
    echo " -q means quiet; do not output anything to the screen.\n";
    echo " -n means no-op; do not actually change any permissions\n";
    echo " -v means show message types: 1=public files, 2=private files, 3=public dirs, 4=private dirs\n";
    echo "\n";
    echo " Example: $argv[0] -s. -v13\n";
    echo "   will scan all files/dirs under the current directory, and print those public files/dirs that need fixing\n";
    echo "\n";
    echo " Public files will have permissions of 0404\n";
    echo " Private files will have permissions of 0400\n";
    echo " Public directories will have permissions of 0707\n";
    echo " Private directories will have permissions of 0505\n";
    echo "\n";
    echo " Public means the web server must serve them up as static files\n";
    echo " Private means that they are accessed only via drupal\n";
    echo "\n";
    echo " NOTE: All .svn directories are ignored.\n";
    echo "\n";
    exit(1);
}

$verbose         = true;
$execute         = true;
$printFlags      = array('public files', 'private files', 'public dirs', 'private dirs');
$numWrong        = 0;
$startDir        = 'bogus-guaranteed-to-fail';
$options         = getopt('s:q::n::v::');
$numPublicFiles  = 0;
$numPrivateFiles = 0;
$numPublicDirs   = 0;
$numPrivateDirs  = 0;
foreach ($options as $key => $value) {
    switch ($key) {
        case 's': $startDir = $value; break;
        case 'n': $execute  = false;  break;
        case 'q': $verbose  = false;  break;
        case 'v':
            $printFlags = array();
            $arr = str_split($value);
            foreach ($arr as $vv) {
                switch ($vv) {
                    case 1: $printFlags[] = 'public files';  break;
                    case 2: $printFlags[] = 'private files'; break;
                    case 3: $printFlags[] = 'public dirs';   break;
                    case 4: $printFlags[] = 'private dirs';  break;
                }
            }
            break;
    }
}

if (is_dir($startDir) === false) {
    echo " Error: startDir is not a directory\n";
    exit(1);
}

$filesToFix = explode("\n", `find ${startDir} -name .svn -prune -o -type f -print`);
foreach ($filesToFix as $filePath) {
    $filePath = trim($filePath);
    if (strlen($filePath) === 0) {
        continue;
    }

    $isPublic = false;

    $fileName = pathinfo($filePath, PATHINFO_BASENAME);
    if (in_array($fileName, $publicFileNames) === true) {
        $isPublic = true;
    }

    if ($isPublic === false) {
        $path = pathinfo($filePath, PATHINFO_DIRNAME);
        foreach ($publicFileDirs as $wfdir) {
            if (strpos($path, '/' . $wfdir) !== false) {
                $isPublic = true;
            }
        }
    }

    if ($isPublic === false) {
        foreach ($publicFileSuffixes as $wfext) {
            if (substr($filePath, -strlen($wfext), strlen($wfext)) === $wfext) {
                $isPublic = true;
                break;
            }
        }
    }

    if ($isPublic === true) {
        $numPublicFiles += fixPerms($filePath, 0404, in_array('public files', $printFlags));
    } else {
        $numPrivateFiles += fixPerms($filePath, 0400, in_array('private files', $printFlags));
    }
}

$dirsToFix    = explode("\n", `find ${startDir} -name .svn -prune -o -type d -print`);
foreach ($dirsToFix as $dirName) {
    $dirName = trim($dirName);
    if (strlen($dirName) === 0) {
        continue;
    }

    $isPublic = false;
    $dir  = pathinfo($dirName, PATHINFO_BASENAME);
    if (in_array($dir, $publicDirs) === true) {
        $isPublic = true;
    }

    if ($isPublic === false) {
        $path = pathinfo($dirName, PATHINFO_DIRNAME);
        foreach ($publicDirs as $wddir) {
            if (strpos($path, '/' . $wddir) !== false) {
                $isPublic = true;
            }
        }
    }

    if ($isPublic === true) {
        $numPublicDirs += fixPerms($dirName, 0707, in_array('public dirs', $printFlags));
    } else {
        $numPrivateDirs += fixPerms($dirName, 0505, in_array('private dirs', $printFlags));
    }
}

if ($numWrong === 0) {
    _log('All files and directories have the correct permissions.');
} else {
    _log('Number of public files with wrong permissions: ' . $numPublicFiles);
    _log('Number of private files with wrong permissions: ' . $numPrivateFiles);
    _log('Number of public dirs with wrong permissions: ' . $numPublicDirs);
    _log('Number of private dirs with wrong permissions: ' . $numPrivateDirs);
    if ($execute === false) {
        _log($numWrong . ' files and/or directories need to be corrected.');
    } else {
        _log($numWrong . ' files and/or directories were corrected.');
    }
}

exit(0);

//----------------------------------------------------------------------------------------
function _log($msg) {
    global $verbose;
    if ($verbose === true) {
        echo ' ' . $msg . "\n";
    }
}

//----------------------------------------------------------------------------------------
// $desiredPerms should be an octal integer value, such as 0400 or 0777
// Returns 1 (indicating permission needed to be fixed) or 0 (meaning it was ok)
function fixPerms($filePath, $desiredPerms, $printLog = false) {
    global $execute, $numWrong;
    $desiredPermsAsString = sprintf("0%o", $desiredPerms);
    $existingPerms = substr(sprintf("%o", fileperms($filePath)), -4);
    if ($existingPerms !== $desiredPermsAsString) {
        if ($printLog === true) {
            _log($desiredPermsAsString . ': ' . $filePath);
        }
        if ($execute === true) {
            chmod($filePath, $desiredPerms);
        }

        ++$numWrong;
        return 1;
    }

    return 0;
}
