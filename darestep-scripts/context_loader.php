<?php
require_once('outputert.php');

use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppInfoDomainRequest;
use Metaregistrar\EPP\eppSecdns;
use Metaregistrar\EPP\eppContactHandle;
use Metaregistrar\EPP\eppHost;

function loadContext($eppConnection, $domainName, $targetOrganization) {
	
	// Determine which context_file to use    
	$interface = $eppConnection->getInterface();
	echo "Loading context file".PHP_EOL;
	echo " Using interface/registry: ".$interface.PHP_EOL;

	$contextFile = "context_".$targetOrganization."_".$interface.".ini";

	$contextDictionary = importContextFile($contextFile);

	echoAndWriteLogfile($domainName,  "Context loaded for domain script: " . $domainName . ":");
	echoAndWriteLogfile($domainName,  http_build_query($contextDictionary,'',', '));

	return $contextDictionary;
}

function importContextFile($file) {
	$filePath = __DIR__ . "\\" . $file;
	echo " filename: " . $filePath.PHP_EOL;
	
	if (is_readable($filePath)) {
		echo " file readable".PHP_EOL;
            $result = [];
            $settings = file($filePath, FILE_IGNORE_NEW_LINES);
            foreach ($settings as $setting) {
                list($param, $value) = explode('=', $setting, 2);
                $param = trim($param);
                $value = trim($value);
                $result[$param] = $value;
            }
            return $result;
        } else {
            throw new Exception("$filePath context file not readable on importContextFile function");
   }
}

function getContextNameServers($contextDictionary) {
	$nameservers = array($contextDictionary["ns1"], $contextDictionary["ns2"], $contextDictionary["ns3"], $contextDictionary["ns4"]);
	$nameservers = array_filter($nameservers);

	return $nameservers;
}