<?php

use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppInfoDomainRequest;
use Metaregistrar\EPP\eppSecdns;
use Metaregistrar\EPP\eppContactHandle;
use Metaregistrar\EPP\eppHost;

function echoAndWriteLogfile($domainname, $logText, $success = true) {
	echo $logText.PHP_EOL;

	$backtrace = debug_backtrace();
	$calling_scriptfile = $backtrace[1]['file'];

	$insertion = $success ? null : "_ERROR";

	$currentScript = pathinfo($calling_scriptfile, PATHINFO_FILENAME);
	$outputPathAndFilenamePrefix = "output\\" . $currentScript . "_" . $domainname . $insertion . ".txt";

	file_put_contents($outputPathAndFilenamePrefix, $logText.PHP_EOL, FILE_APPEND);
}

function exportDomainInfoToCsv($domainInfoResponse) {

	$domain = $domainInfoResponse->getDomain();
	$domainName = $domain->getDomainname();

	$columnHeaders = array("domain");
	$columns = array($domainName);
	echo " Exporting domain: " . $domainName;

	$handleRegistrant = $domain->getRegistrant();
	array_push($columnHeaders, "registrant");
	array_push($columns, $handleRegistrant);
	
	// ADMIN Handles (2x)
	array_push($columnHeaders, "admin1");
	array_push($columnHeaders, "admin2");

	// TECH Handles (3x)
	array_push($columnHeaders, "tech1");
	array_push($columnHeaders, "tech2");
	array_push($columnHeaders, "tech3");

	// BILLING handle (1x)
	array_push($columnHeaders, "billing1");

	$handlesAdmin = array();
	$handlesTech = array();
	$handlesBilling = array();
	
	$handles = $domainInfoResponse->getDomainContacts();

	if(is_array($handles)) {
		foreach($handles as $handle) {
			$contactType = $handle->getContactType();
			switch ($contactType) {
				 case "admin":
					array_push($handlesAdmin, $handle->getContactHandle());
					break;
				case "tech":
					array_push($handlesTech, $handle->getContactHandle());
					break;
				case "billing":
					array_push($handlesBilling, $handle->getContactHandle());
					break;
			}
		}
	}

	//Fill up to fixed amount, and cut of to that limit (in case more are applicable)
	$handlesAdmin	= array_pad($handlesAdmin	 , 2, null);
	$handlesAdmin	= array_slice($handlesAdmin	 , 0, 2);
	$handlesTech	= array_pad($handlesTech	 , 3, null);
	$handlesTech	= array_slice($handlesTech	 , 0, 3);
	$handlesBilling = array_pad($handlesBilling	 , 1, null);
	$handlesBilling = array_slice($handlesBilling, 0, 1);

	array_push($columns, $handlesAdmin[0]);
	array_push($columns, $handlesAdmin[1]);
	array_push($columns, $handlesTech[0]);
	array_push($columns, $handlesTech[1]);
	array_push($columns, $handlesTech[2]);
	array_push($columns, $handlesBilling[0]);

	// DNSSEC
	array_push($columnHeaders, "dnssec");
	array_push($columns, $domainInfoResponse->hasDnsSec());

	// NAMESERVERS (4x)
	array_push($columnHeaders, "ns1");
	array_push($columnHeaders, "ns2");
	array_push($columnHeaders, "ns3");
	array_push($columnHeaders, "ns4");

	$nameservers = array();
	foreach ($domain->getHosts() as $index=>$nameserver) {
		array_push($nameservers, $nameserver->getHostname());

		if($index === 3) { break; } // take only the first four
	}
	$nameservers = array_pad($nameservers	, 4, null);
	$nameservers = array_slice($nameservers , 0, 4);

	array_push($columns, $nameservers[0]);
	array_push($columns, $nameservers[1]);
	array_push($columns, $nameservers[2]);
	array_push($columns, $nameservers[3]);

	// Created date
	array_push($columnHeaders, "created");
	array_push($columns, $domainInfoResponse->getDomainCreateDate());
	// Updated date
	array_push($columnHeaders, "updated");
	array_push($columns, $domainInfoResponse->getDomainUpdateDate());

	// Exported date
	array_push($columnHeaders, "--exported--");
	array_push($columns, gmdate(DATE_ISO8601));

	// print_r($columnHeaders);
	// print_r($columns);

	appendDomainInfoOverview($columnHeaders, $columns);
	createDomainInfoDomainOnly($domainName, $columnHeaders, $columns);
}

function appendDomainInfoOverview($columnHeaders, $columnValues) {
	$outputFile = "output\\__exports.csv";

	$filepointer = fopen($outputFile, 'a+');

	$headerLine = fgets($filepointer);
	if(empty($headerLine)) {
		fputcsv($filepointer, $columnHeaders, "|");
		fseek($filepointer, 0, SEEK_END);
	}

	fputcsv($filepointer, $columnValues, "|");
	
	fclose($filepointer);

	echo " --> " . $outputFile . PHP_EOL;
}

function createDomainInfoDomainOnly($domainName, $columnHeaders, $columnValues) {
	$outputFile = "output\\" . $domainName . "_export_.csv";

	$filepointer = fopen($outputFile, 'a+');

	$headerLine = fgets($filepointer);
	if(empty($headerLine)) {
		fputcsv($filepointer, $columnHeaders, "|");
		fseek($filepointer, 0, SEEK_END);
	}

	fputcsv($filepointer, $columnValues, "|");
	
	fclose($filepointer);

	echo " --> " . $outputFile . PHP_EOL;
}

