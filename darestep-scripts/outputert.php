<?php

function echoAndWriteLogfile($domainname, $logText, $success = true) {
	echo $logText.PHP_EOL;

	$backtrace = debug_backtrace();
	$calling_scriptfile = $backtrace[1]['file'];

	$insertion = $success ? null : "_ERROR";

	$currentScript = pathinfo($calling_scriptfile, PATHINFO_FILENAME);
	$outputPathAndFilenamePrefix = "output\\" . $currentScript . "_" . $domainname . $insertion . ".txt";

	file_put_contents($outputPathAndFilenamePrefix, $logText.PHP_EOL, FILE_APPEND);
}