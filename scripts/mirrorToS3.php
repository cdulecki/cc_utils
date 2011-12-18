#!/usr/local/bin/php
<?php
// setup zend autoloader
$zendLocation = '/<REPLACE-ME>/lib/Zend/library';
set_include_path($zendLocation . ':' . get_include_path());
require $zendLocation . '/Zend/Loader/Autoloader.php';
$autoloader = Zend_Loader_Autoloader::getInstance();

if ($argc === 1) {
    echo "\n";
    echo " Usage: $argv[0] -s<srcDir> -b<bucket> [-v<0|1|2|3|4>] [-i<0|1|2|n>] [-k] [-c] [-t] [-z<number>] [-n]\n";
    echo "\n";
    echo " This script makes a exact replica (mirror) of a directory tree on s3.\n";
    echo " When done, the s3 bucket will exactly match the directory tree (unless -k).\n";
    echo "\n";
    echo "  -s Local source directory\n";
    echo "  -b S3 bucket with which to sync\n";
    echo "  -v Verbosity level: 0=quiet (default),  1=files modified, 2=all files, 3=scanned dirs, 4=skipped dirs\n";
    echo "  -i Log msg indent level if verbosity >= 1, 0=no nesting, 1=2 spaces (default), 2=4 spaces, etc.\n";
    echo "  -k Keep S3 files that are NOT on the filesystem (default is to delete from s3)\n";
    echo "  -c Create bucket if it does not exist, otherwise error\n";
    echo "  -t Threads the sync, so that multiple files are uploaded at the same time\n";
    echo "  -z If threading, number will be how many uploads to fork at once (defaults to " . MirrorToS3::$threadBatchSize . ")\n";
    echo "  -n No-op: don't do anything, but show what you WOULD do\n";
    echo "\n";
    exit(1);
}


try {
    $startTime = time();
    $obj = new MirrorToS3(getopt('s:b:v::i::n::k::c::t::z::'));
    $obj->getS3ObjectList();
    $obj->syncList();
    $obj->removeS3stragglers();
    $endTime = time();
    //echo 'Script complete, duration in minutes: ' . round(abs($endTime - $startTime) / 60, 2) . "\n";
} catch (Exception $ex) {
    echo 'Exception thrown, msg=[' . $ex->getMessage() . "]\n";
    exit(1);
}

exit(0);



class MirrorToS3
{
    const     LOG_FILE_MOD        = 1;  // display decisions on only modified/uploaded files
    const     LOG_FILE_ALL        = 2;  // display decisions on all files
    const     LOG_SCAN_DIR        = 3;  // display messages on which directories are scanned
    const     LOG_SKIP_DIR        = 4;  // display messages on which directories are skipped
    protected $_awsAccessKey      = 'YOUR ACCESS KEY';
    protected $_awsSecretKey      = 'YOUR SECRET KEY';
    protected $_skipDirs          = array('.svn');
    protected $_skipFilesPrefixes = array();
    protected $_skipFilesSuffixes = array('.swp');

    protected $_verbose           = 0;
    protected $_topDir            = null;
    protected $_bucket            = null;
    protected $_indentOutputLevel = 1;  // 0=0 space indent, 1=2 spaces, 2=4 spaces, etc.
    protected $_createBucket      = false;
    protected $_keep              = false;
    protected $_execute           = true;
    protected $_threaded          = false;
    protected $_threadData        = array();
    protected $_s3;
    protected $_s3objList;

    public static $threadBatchSize = 3;

    //----------------------------------------------------------------------------------
    public function __construct($options) {
        foreach ($options as $key => $value) {
            switch ($key) {
                case 's': $this->_topDir             = $value;                          break;
                case 'b': $this->_bucket             = $value;                          break;
                case 'v': $this->_verbose            = (int) $value;                    break;
                case 'i': $this->_indentOutputLevel  = ($value === false ? 1 : $value); break;
                case 'k': $this->_keep               = true;                            break;
                case 'c': $this->_createBucket       = true;                            break;
                case 't': $this->_threaded           = true;                            break;
                case 'n': $this->_execute            = false;                           break;
                case 'z': self::$threadBatchSize     = (int) $value;                    break;
            }
        }

        if (empty($this->_topDir) === true) {
            throw new Exception("Source Directory not specified");
        }

        if (empty($this->_bucket) === true) {
            throw new Exception("Bucket not specified");
        }

        if ($this->_verbose < 0 or $this->_verbose > 4) {
            throw new Exception("Invalid verbosity level specified");
        }

        $this->_s3 = new Zend_Service_Amazon_S3($this->_awsAccessKey, $this->_awsSecretKey);
    }

    //----------------------------------------------------------------------------------
    public function getS3ObjectList() {
        $this->_s3objList = $this->_s3->getObjectsByBucket($this->_bucket);
        if ($this->_s3objList === false) {
            // bucket not found, try to create it
            if ($this->_s3->isBucketAvailable($this->_bucket) === true) {
                throw new Exception("isBucketAvailaable failure.");
            }

            if ($this->_createBucket === false) {
                throw new Exception("Bucket not found or error accessing, and create-bucket-flag not specified.");
            } else {
                $this->_log('Bucket ' . $this->_bucket . ' not found; attempting to create', 0, self::LOG_FILE_MOD);
                if ($this->_execute === true) {
                    if ($this->_s3->createBucket($this->_bucket) === false) {
                        throw new Exception("createBucket failure.");
                    }
                }
            }

            $this->_s3objList = array();
        }
    }

    //----------------------------------------------------------------------------------
    protected function _log($msg, $indentationLevel = 0, $verbosity = 0) {
        if ($this->_verbose >= $verbosity) {
            echo date('Y-m-d H:i:s ');
            for ($ii = 0; $ii < $indentationLevel; $ii++) {
                echo "  ";
            }

            echo $msg . "\n";
        }
    }

    //----------------------------------------------------------------------------------
    public function syncList($currentDir = null, $prefix = null, $indentLevel = 0) {
        if (is_null($prefix) === true) {
            // set initial directory for scan
            $currentDir = $this->_topDir;
            
            if ($this->_execute === false) {
                $this->_log("---- NO-OP: Nothing in S3 will be affected -----", $indentLevel, self::LOG_FILE_MOD);
            }
        }

        $dir = opendir($currentDir);
        if ($dir === false) {
            throw new Exception("Unable to open directory: [$dir], skipping");
            return;
        }

        $this->_log("Scanning local directory: $currentDir", $indentLevel, self::LOG_SCAN_DIR);

        while($entryName = readdir($dir))
        {
            if ($entryName)
            {
                if ($entryName === '.' or $entryName === '..') {
                    continue;
                }

                if (is_dir($currentDir . DIRECTORY_SEPARATOR . $entryName) === true) {
                    $newPrefix = ($prefix != null ?  $prefix . DIRECTORY_SEPARATOR . $entryName : $entryName);

                    if (in_array($entryName, $this->_skipDirs) === false) {
                        $this->syncList($currentDir . DIRECTORY_SEPARATOR . $entryName, $newPrefix, $indentLevel + $this->_indentOutputLevel);
                    } else {
                        $this->_log("Skipping directory: [" . ($prefix ? $prefix . DIRECTORY_SEPARATOR : '') . $entryName . "]", $indentLevel, self::LOG_SKIP_DIR);
                    }
                }
                else {
                    if (is_null($prefix) === true) {
                        $s3Name = $entryName;
                        $filePath = $currentDir . DIRECTORY_SEPARATOR . $entryName;
                    } else {
                        $s3Name = $prefix . DIRECTORY_SEPARATOR . $entryName;
                        $filePath = $currentDir . DIRECTORY_SEPARATOR . $entryName;
                    }
    
                    $this->_handleFile($filePath, $s3Name, $indentLevel);
                }
            } else {
                throw new Exception("ERROR: readdir($dir) failed, continuing");
            }
        }

        closedir($dir);

        // if top level and the flags are set right, then fork child processes to upload files
        if (is_null($prefix) === true and $this->_threaded === true and $this->_execute === true) {
            $this->threadedUpload();
        }
    }

    //----------------------------------------------------------------------------------
    protected function _handleFile($filePath, $s3Name, $indentLevel) {
        // Validation of nasty logic: Ensure the names are correct
        if (substr($filePath, 0, strlen($this->_topDir) + 1)  !== $this->_topDir . DIRECTORY_SEPARATOR) {
            echo "Severe logic error, skipping file, filePath=[$filePath], bucket=[$this->_bucket], s3Name=[$s3Name], topdir=[$this->_topDir]";
            return;
        }

        $putFile = false;
        $s3object = $this->_bucket . '/' . $s3Name;

        $s3info = $this->_s3->getInfo($s3object);
        if ($s3info === false) {
            if ($this->_threaded === true) {
                $this->_log('New file: [' . $s3Name . ']', $indentLevel, self::LOG_FILE_MOD);
            } else {
                $this->_log('New file, uploading to s3: [' . $s3Name . ']', $indentLevel, self::LOG_FILE_MOD);
            }
            $putFile = true;
        } else {
            $fileInfo = lstat($filePath);

            if ($fileInfo['mtime'] > $s3info['mtime']) {
                if ($this->_threaded === true) {
                    $this->_log('Modification time changed: [' . $s3Name . ']', $indentLevel, self::LOG_FILE_MOD);
                } else {
                    $this->_log('Modification time changed, uploading to s3: [' . $s3Name . ']', $indentLevel, self::LOG_FILE_MOD);
                }

                $putFile = true;
            } else if ($fileInfo['size'] != $s3info['size']) {
                if ($this->_threaded === true) {
                    $this->_log('File size changed: [' . $s3Name . ']', $indentLevel, self::LOG_FILE_MOD);
                } else {
                    $this->_log('File size changed, uploading to s3: [' . $s3Name . ']', $indentLevel, self::LOG_FILE_MOD);
                }

                $putFile = true;
            } else {
                $this->_log('No change, file skipped: [' . $s3Name . ']', $indentLevel, self::LOG_FILE_ALL);
            }
        }

        if ($this->_execute === true and $putFile === true) {
            if ($this->_threaded === true) {
                $this->_threadData[] = array('filepath' => $filePath, 's3object' => $s3object);
            } else {
                $this->_s3->putFileStream($filePath, $s3object, array(Zend_Service_Amazon_S3::S3_ACL_HEADER => Zend_Service_Amazon_S3::S3_ACL_PRIVATE));
            }
        }

        // Remove from the list of s3 objects
        $idx = array_search($s3Name, $this->_s3objList);
        if ($idx !== false) {
            unset($this->_s3objList[$idx]);
        }
    }

    //----------------------------------------------------------------------------------
    public function threadedUpload() {
        // spawn children in groups, so as to not overwhelm the process table with thousands of child processes
        $numToFork = count($this->_threadData);
        $numSets = (int)ceil($numToFork / self::$threadBatchSize);
        for ($setNum = 0; $setNum < $numSets; $setNum++) {
            $offset = $setNum * self::$threadBatchSize;
            $batch = array_slice($this->_threadData, $offset, self::$threadBatchSize);

            // spawn child processes to upload files
            $childPids = array();
            foreach($batch as $entry) {
                $this->_log('Spawning process to upload to s3: [' . $entry['s3object'] . ']', $indentLevel, self::LOG_FILE_MOD);
                $pid = pcntl_fork();
                if ($pid === -1) {
                    die("ERROR FORKING!");
                } else if ($pid === 0) {
                    // child process
                    $this->_s3->putFileStream($entry['filepath'], $entry['s3object'], array(Zend_Service_Amazon_S3::S3_ACL_HEADER => Zend_Service_Amazon_S3::S3_ACL_PRIVATE));
                    exit(0);
                }

                $childPids[] = $pid;
            }

            // wait for child processes to end
            $this->_log('Waiting for pids: [' . implode(',', $childPids) . ']', 0, self::LOG_FILE_MOD);
            foreach ($childPids as $pid) {
                $rv = 0;
                pcntl_waitpid($pid, $rv);
            }
        }
    }

    //----------------------------------------------------------------------------------
    // This is not threaded as deletes shouldn't take long
    public function removeS3stragglers() {
        if ($this->_keep === false) {
            foreach ($this->_s3objList as $s3name) {
                $this->_log('Deleting object from s3: [' . $s3name . ']', 0, self::LOG_FILE_MOD);
                if ($this->_execute === true) {
                    $this->_s3->removeObject($this->_bucket . '/' . $s3name);
                }
            }
        } else {
            $this->_log(count($this->_s3objList) . ' file(s) detected in s3 that do not exist on filesystem:', 0, self::LOG_FILE_MOD);
            foreach ($this->_s3objList as $s3name) {
                $this->_log($s3name, 2, self::LOG_FILE_MOD);
            }

            $this->_log('--- END OF LIST ---', 0, self::LOG_FILE_MOD);
        }
    }
}   // end MirrorToS3 class
