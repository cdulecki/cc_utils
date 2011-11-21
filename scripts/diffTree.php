#!/usr/local/bin/php
<?php

//*************************************************************************
//
// This function scans a directory structure and returns a numeric array
// where each element is a directory/subdirectory.  No files are included.
// 
// NOTE: This is a recursive function.  $prefix should be NULL for the
// initial (top level) call.
//
function dirTreeList($topLevelDir, &$arrayToFill, $prefix = NULL)
{
    global $noSvn;

    $myDirectory = opendir($topLevelDir);
    if ($myDirectory) {
        while($entryName = readdir($myDirectory)) {
            if ($entryName) {
                switch (TRUE) {
                    case ($entryName == '..'):    // ignore
                    case ($entryName == '.'):
                    case ($noSvn === true and $entryName == '.svn'):
                        break;

                    case (is_dir($topLevelDir . '/' . $entryName)):
                        if ($prefix == NULL) {
                            $newPrefix = $entryName;
                        } else {
                            $newPrefix = $prefix . '/' . $entryName;
                        }

                        dirTreeList($topLevelDir . '/' . $entryName, $arrayToFill, $newPrefix);

                        if ($prefix != NULL) {
                            $arrayToFill[] = $prefix . '/' . $entryName;
                        } else {
                            $arrayToFill[] = $entryName;            // top level directory
                        }
                        break;

                    default:                    // file: ignore
                        break;
                }
            }
        }

        closedir($myDirectory);
    }

}    // end dirTreeList()


//*************************************************************************
//
// This function scans a directory and returns a numeric array of files
// in that directory.  Subdirectories are not included.
//
function dirFileList($dir, &$arrayToFill)
{
    $myDirectory = opendir($dir);
    if ($myDirectory) {
        while($entryName = readdir($myDirectory)) {
            if ($entryName) {
                switch (TRUE) {
                    case ($entryName==".."):    // ignore
                    case ($entryName=="."):
                        break;

                    case (!is_dir($dir . '/' . $entryName)):
                        $arrayToFill[] = $entryName;
                        break;

                    default:                    // directory: ignore
                        break;
                }
            }
        }

        closedir($myDirectory);
    }

}    // end dirFileList()


if ($argc != 3 and $argc != 4)
{
    echo " \n";
    echo " USAGE: $argv[0] <dir1> <dir2> [nosvn]\n";
    echo " \n";
    echo " This utility compares two directory trees, providing information\n";
    echo " on differences between files and sub-directories.\n";
    echo " \n";
    echo " Specify nosvn to ignore any .svn directories\n";
    echo " \n";
    exit;
}


$topDir1 = $argv[1];
$topDir2 = $argv[2];
$noSvn   = ($argc === 4 ? true : false);


if (!is_dir($topDir1)) {
    echo "ERROR: $topDir1 does not exist!\n";
    exit;
}

if (!is_dir($topDir2)) {
    echo "ERROR: $topDir2 does not exist!\n";
    exit;
}

dirTreeList($topDir1, $dirTree1, NULL);
dirTreeList($topDir2, $dirTree2, NULL);


// If null is returned, then there is no tree/subdirs - only files
if ($dirTree1 == null) {
    $dirTree1 = array();
}

if ($dirTree2 == null) {
    $dirTree2 = array();
}


sort($dirTree1);
sort($dirTree2);

//var_dump($dirTree1);
//var_dump($dirTree2);


$commonDirs = array();
$missingFromD1 = array();
$missingFromD2 = array();

// Find the directories in tree1 that also exist in tree2
foreach ($dirTree1 as $d1) {
    if (in_array($d1, $dirTree2)) {
        $commonDirs[] = $d1;
    } else {
        $missingFromD2[] = $d1;
    }
}


// Find the directories in tree2 that also exist in tree1
foreach ($dirTree2 as $d2) {
    if (!in_array($d2, $dirTree1)) {
        $missingFromD1[] = $d2;
    }
}


//var_dump($missingFromD1);
//var_dump($missingFromD2);
//var_dump($commonDirs);


echo " -------- DIRECTORY STATUS ---------\n";

echo ($noSvn === true ? " Important: Skipping .svn directories\n" : '');

echo " The two directories have ".count($commonDirs)." subdirectories in common.\n";

if (count($missingFromD1) == 0) {
    echo " All directories in $topDir1 also exist in $topDir2\n";
} else {
    echo " IN $topDir2 NOT IN $topDir1:\n";
    foreach ($missingFromD1 as $d1) {
        echo "    $d1\n";
    }

    echo "    END OF LIST\n";
    echo "\n";
}


if (count($missingFromD2) == 0) {
    echo " All directories in $topDir2 also exist in $topDir1\n";
} else {
    echo " IN $topDir1 NOT IN $topDir2:\n";
    foreach ($missingFromD2 as $d2) {
        echo "    $d2\n";
    }

    echo "    END OF LIST\n";
    echo "\n";
}



//-----------------------------------------------------------------------------


// get the file list of each file in the top level directories
$topList1 = array();
$topList2 = array();

$fileList = array();
dirFileList($topDir1, $fileList);
foreach ($fileList as $file) {
    $topList1[$file] = filesize($topDir1.'/'.$file);
}

asort($topList1);

$fileList = array();
dirFileList($topDir2, $fileList);
foreach ($fileList as $file) {
    $topList2[$file] = filesize($topDir2.'/'.$file);
}

asort($topList2);


//var_dump($topList1);
//var_dump($topList2);


// convert commonDirs into two associative arrays with dirname being index,
// and get the files for each subdir, including each file's size

$commonDirs1 = array();
$commonDirs2 = array();
foreach ($commonDirs as $dir) {
    $commonDirs1[$dir] = array();
    $currDir = $topDir1.'/'.$dir.'/';

    $fileList = array();
    dirFileList($topDir1.'/'.$dir, $fileList);
    foreach ($fileList as $file) {
        $commonDirs1[$dir][$file] = filesize($currDir.$file);
    }

    asort($commonDirs1[$dir]);

    $commonDirs2[$dir] = array();
    $currDir = $topDir2.'/'.$dir.'/';

    $fileList = array();
    dirFileList($topDir2.'/'.$dir, $fileList);
    foreach ($fileList as $file) {
        $commonDirs2[$dir][$file] = filesize($currDir.$file);
    }

    asort($commonDirs2[$dir]);
}


//var_dump($commonDirs1);
//var_dump($commonDirs2);



// ensure we have synchronicity - high level arrays should be identical
if (count($commonDirs1) != count($commonDirs2)) {
    echo "--------- SNHE: commonDirs1 not same size as commonDirs2! -------\n";
    echo "commonDirs1:\n";
    var_dump($commonDirs1);
    echo "commonDirs2:\n";
    var_dump($commonDirs2);
    exit;
}




//-----------------------------------------------------------------------------


// In the top level directory, get files that are missing, keep track of them,
// and remove them so that the $topList1 and $topList2 have the same entries.

$tmpFilesIn1Not2 = array_diff_key($topList1, $topList2);
$tmpFilesIn2Not1 = array_diff_key($topList2, $topList1);
$commonFileList = array();

foreach ($tmpFilesIn1Not2 as $file => $size) {
    unset($topList1[$file]);
}

foreach ($tmpFilesIn2Not1 as $file => $size) {
    unset($topList2[$file]);
}

$differingFiles = array();
foreach ($topList1 as $file => $size) {
    if ($topList2[$file] != $size) {
        $differingFiles[$file] = $file." (size mismatch, $size bytes vs. ".$topList2[$file].")";
    } else {
        // same size, ensure bytes match

        $cmd = "cmp -s \"".$topDir1.'/'.$file."\" \"" . $topDir2.'/'.$file."\"";

        $rv = 0;
        system($cmd, $rv);
        if ($rv == 1) {
            $differingFiles[$file] = $file." (contents differ)";
        } else if ($rv > 1) {
            echo "---- SNHE: cmp returned $rv! ----\n";
            echo "CMD1=[$cmd]\n";
            exit;
        } else {
            // identical content
            $commonFileList[$file] = 0;
        }
    }
}



//var_dump($topList1);
//var_dump($topList2);
//var_dump($commonFileList);


$filesIn1Not2 = array();
$filesIn2Not1 = array();

// convert missing files into readable format
foreach ($tmpFilesIn1Not2 as $file => $size) {
    $filesIn1Not2[$file] = $file." ($size bytes)";
}

foreach ($tmpFilesIn2Not1 as $file => $size) {
    $filesIn2Not1[$file] = $file." ($size bytes)";
}


foreach ($commonDirs1 as $dir => $fileList) {
    foreach ($fileList as $file => $size) {
        $fileName = $dir.'/'.$file;

        if (!isset($commonDirs2[$dir][$file])) {
            $filesIn1Not2[$fileName] = $fileName." ($size bytes)";
        } else {
            if ($commonDirs2[$dir][$file] != $size) {
                $differingFiles[$fileName] = $fileName . " (size mismatch, $size bytes vs. " . $commonDirs2[$dir][$file].")";
            } else {
                // same size, ensure bytes match

                $cmd = "cmp -s \"".$topDir1.'/'.$fileName."\" \"" . $topDir2.'/'.$fileName."\"";

                $rv = 0;
                system($cmd, $rv);
                if ($rv == 1) {
                    $differingFiles[$fileName] = $fileName." (contents differ)";
                } else if ($rv > 1) {
                    echo "---- SNHE: cmp returned $rv! ----\n";
                    echo "CMD2=[$cmd]\n";
                    exit;
                } else {
                    // identical content
                    $commonFileList[$fileName] = 0;
                }
            }
        }
    }
}


foreach ($commonDirs2 as $dir => $fileList) {
    foreach ($fileList as $file => $size) {
        $fileName = $dir.'/'.$file;

        if (!isset($commonDirs1[$dir][$file])) {
            $filesIn2Not1[$fileName] = $fileName." ($size bytes)";
        } else {
            if ($commonDirs1[$dir][$file] != $size) {
                $differingFiles[$fileName] = $fileName . " (size mismatch, $size bytes vs. " . $commonDirs1[$dir][$file].")";
            } else {
                // same size, ensure bytes match

                $cmd = "cmp -s \"".$topDir1.'/'.$fileName."\" \"" . $topDir2.'/'.$fileName."\"";

                $rv = 0;
                system($cmd, $rv);
                if ($rv == 1) {
                    $differingFiles[$fileName] = $fileName." (contents differ)";
                } else if ($rv > 1) {
                    echo "---- SNHE: cmp returned $rv! ----\n";
                    echo "CMD3=[$cmd]\n";
                    exit;
                } else {
                    // identical content
                    $commonFileList[$fileName] = 0;
                }
            }
        }
    }
}



//var_dump($filesIn1Not2);
//var_dump($filesIn2Not1);
//var_dump($differingFiles);

echo " -------- FILE STATUS ---------\n";
echo " NOTE: Only those files contained in matching subdirectories are considered.\n";
echo " There are ".count($commonFileList)." files in common.\n";

if (count($differingFiles) == 0) {
    echo " All files present in both $topDir1 and $topDir2 are identical.\n";
} else {
    sort($differingFiles);
    echo " DIFFERENT:\n";
    foreach ($differingFiles as $file => $txt) {
        echo "    $txt\n";
    }

    echo "    END OF LIST\n";
    echo "\n";
}


if (count($filesIn1Not2) > 0) {
    echo " IN $topDir1 NOT IN $topDir2:\n";
    foreach ($filesIn1Not2 as $file => $txt) {
        echo "    $txt\n";
    }

    echo "    END OF LIST\n";
    echo "\n";
}


if (count($filesIn2Not1) > 0) {
    echo " IN $topDir2 NOT IN $topDir1:\n";
    foreach ($filesIn2Not1 as $file => $txt) {
        echo "    $txt\n";
    }

    echo "    END OF LIST\n";
    echo "\n";
}


echo " --------- END OF REPORT ----------\n";


//var_dump($commonFileList);
