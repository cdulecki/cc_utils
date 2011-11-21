#!/usr/local/bin/php
<?php
if ($argc === 1) {
    echo "\n";
    echo " USAGE: $argv[0] -r<repoDir> -b<baseUrl> -u<svnUser> -p<svnPass> -d<buDir> [-v] [-t]\n";
    echo "\n";
    echo " -r repoDir is the directory where the repositories live\n";
    echo " -b baseUrl is the base svn url\n";
    echo " -u svnUser is the svn username\n";
    echo " -p svnPass is the svn password\n";
    echo " -d buDir is where the backup files should be placed\n";
    echo " -v verbose logging; default is no output unless error\n";
    echo " -t thread the backups so they run in parallel\n";
    echo "\n";
    echo " Example: $argv[0] -r../svn -uhttp://www.example.com -sadmin -umyPassword\n";
    echo "\n";
    exit(1);
}
 
$options = array('repoDir'        => '',
                 'baseUrl'        => '',
                 'svnUser'        => '',
                 'svnPass'        => '',
                 'buDir'          => '',
                 'verbose'        => false,
                 'thread'         => false,
                 );
$cliOpts = getopt('r:b:u:p:s:d:v::t::');
foreach ($cliOpts as $key => $value) {
    switch ($key) {
        case 'r': $options['repoDir'] = rtrim($value, '/'); break;
        case 'b': $options['baseUrl'] = rtrim($value, '/'); break;
        case 'u': $options['svnUser'] = $value;             break;
        case 'p': $options['svnPass'] = $value;             break;
        case 'd': $options['buDir']   = rtrim($value, '/'); break;
        case 'v': $options['verbose'] = true;               break;
        case 't': $options['thread']  = true;               break;
    }
}

if (optionsAreValid($options) === false) {
    echo "Aborting program.\n";
    exit(1);
}

$options['svnCmd'] = 'svn --username ' . $options['svnUser'] . ' --password ' . $options['svnPass'] . ' --no-auth-cache --non-interactive ';
$options['repos']  = array_map('calculateRepo', glob($options['repoDir'] . '/*.access'));

_log("Gathering repo data...");
$repoData = getRepoData($options);
if (count($repoData) <= 0) {
    echo " No repos are accessible, aborting program.\n";
    exit(1);
}

_log('Gathering data on last backups...');
getExistingBackups($repoData, $options);
if (createBackupFiles($repoData, $options) === 0) {
    _log('All backups up to date; nothing backed up');
} else {
    _log('Done creating backup files');
}


exit(0);


//------------------------------------------------------------------------
function optionsAreValid(&$options) {
    if (is_dir($options['repoDir']) === false) {
        echo ' ERROR: repoDir [' . $options['repoDir'] . "] is not a directory\n";
        return false;
    }

    if (is_dir($options['buDir']) === false) {
        echo ' ERROR: buDir [' . $options['buDir'] . "] is not a directory\n";
        return false;
    }

    if (is_writable($options['buDir']) !== true) {
        echo ' ERROR: buDir [' . $options['buDir'] . "] is not writable\n";
        return false;
    }

    return true;
}

//------------------------------------------------------------------------
function _log($msg) {
    global $options;
    if ($options['verbose'] === true) {
        echo date('Y-m-d H:i:s') . "-$msg\n";
    }
}

//------------------------------------------------------------------------
function calculateRepo($entry) {
    // find files ending in ".access"
    $file = basename($entry, '.access');
    return $file;
}

//------------------------------------------------------------------------
function extractField($data, $which = 1, $delimiter = ':') {
    $which--;   // parameter is 1-based; array is 0-based
    $arr = explode($delimiter, trim($data));
    if (count($arr) < $which) {
        return '';
    }

    return trim($arr[$which]);
}

//------------------------------------------------------------------------
function checkUrl($url, $svnUser = '', $svnPass = '') {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_HEADER         => 1,
        CURLOPT_NOBODY         => 1,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_USERPWD        => $svnUser . ':' . $svnPass,
    ));
    curl_exec($ch);
    return curl_getinfo($ch, CURLINFO_HTTP_CODE);
}

//------------------------------------------------------------------------
function getRepoData($options) {
    $list = array();
    $svnCmd = $options['svnCmd'];
    foreach ($options['repos'] as $repo) {
        $url          = $options['baseUrl'] . '/' . $repo . '/';
        $responseCode = checkUrl($url, $options['svnUser'], $options['svnPass']);
        if ($responseCode !== 200) {
            echo ' ERROR: http response ' . $responseCode . ' returned from ' . $url . ", skipping repo\n";
            continue;
        }

        $repoRevision     = extractField(`$svnCmd info $url | grep Revision:`, 2);
        $repoLastRevision = extractField(`$svnCmd info $url | grep "Last Changed Rev:"`, 2);

        $newEntry                     = array();
        $newEntry['url']              = $url;
        $newEntry['repo']             = $repo;
        $newEntry['repoRevision']     = max($repoRevision, $repoLastRevision);
        $newEntry['backupRevision']   = -1;
        $newEntry['previousFileName'] = '';
        $list[$repo]                  = $newEntry;
    }

    return $list;
}

//------------------------------------------------------------------------
function getExistingBackups(&$repoData, $options) {
    foreach ($repoData as $repo => $data) {
        // Ex: cc_utils-r1-5.svn
        $files = glob($options['buDir'] . '/' . $repo . '-r1-*.svn');
        if (count($files) > 0) {
            $repoData[$repo]['previousFileName'] = $files[0];
            $file = basename($files[0], '.svn');
            $parts = explode('-', $files[0]);
            $revision = $parts[count($parts) - 1];
            $repoData[$repo]['backupRevision'] = (int)$revision;
        }
    }
}

//------------------------------------------------------------------------
function createBackupFiles($repoData, $options) {
    $numBackedUp = 0;
    foreach ($repoData as $repo) {
        if ($repo['backupRevision'] < $repo['repoRevision']) {
            ++$numBackedUp;
            $buPath   = $options['buDir'] . '/' . $repo['repo'] . '-r1-' . $repo['repoRevision'] . '.svn';
            $repoPath = $options['repoDir'] . '/' . $repo['repo'];
            $cmd      = "svnadmin dump -q $repoPath > $buPath 2>/dev/null";

            if (file_exists($repo['previousFileName']) === true) {
                unlink($repo['previousFileName']);
            }

            if ($options['thread'] === true) {
                _log('Backing up ' . $repo['repo'] . ' to ' . $buPath . ' (in the background)');
                exec($cmd . ' &');
            } else {
                _log('Backing up ' . $repo['repo'] . ' to ' . $buPath);
                exec($cmd);
            }
        }
    }

    return $numBackedUp;
}



