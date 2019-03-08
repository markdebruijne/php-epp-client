<?php
require('autoloader.php');
require_once('outputert.php');

use Metaregistrar\EPP\eppConnection;
use Metaregistrar\EPP\eppException;
use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppInfoDomainRequest;
use Metaregistrar\EPP\eppSecdns;
use Metaregistrar\EPP\eppContactHandle;
use Metaregistrar\EPP\eppHost;

/*
 * This script retrieves all information for a specific domain name.
 * In case the domain is not (yet) in your portfolio, with help of the
 * given authorization code, the info can be retrieved from the current
 * registrar.
 * 
 * Usage: infodomain.php <domainname> <authcode>
 */

if ($argc <= 1 or $argc > 4) { // argc[0] is the script name
    echo "Usage: infodomain.php <domainname> <authcode>\n";
    echo "Please enter as input:\n";
	echo " Argument 1 (required): domain name.\n";
	echo " Argument 2 (optional): authorization code/token.\n";
	echo " Argument 3 (optional)  export.\n";
    die();
}
$domainname = $argv[1];
$domainAuthCode = $argc === 3 && $argv[2] !== "export"? $argv[2] : null;
$exportDomainInfo = ($argc === 4 || ($argc === 3 && $argv[2] === "export"));

echo "Retrieving info on " . $domainname . "\n";
if(!empty($domainAuthCode)) {
	echo " -> with help of authCode (as domain probably is not in control yet).\n";
}

try {
    // Please enter your own settings file here under before using this example
    if ($conn = eppConnection::create('settings.ini', true)) {
        // Connect to the EPP server
        if ($conn->login()) {
            $result = infodomain($conn, $domainname, $domainAuthCode);
            $conn->logout();
		
			if($exportDomainInfo) {
				exportDomainInfoToCsv($result);
			}

        }
    }
} catch (eppException $e) {
    echo "ERROR: " . $e->getMessage() . "\n\n";
}

/**
 * @param $conn Metaregistrar\EPP\eppConnection
 * @param $domainname string
 * @return string
 */
function infodomain($conn, $domainname, $domainAuthCode) {
	$domainObject = new eppDomain($domainname);
	if(!empty($domainAuthCode)) {
		$domainObject->setAuthorisationCode($domainAuthCode);
	}
    $info = new eppInfoDomainRequest($domainObject);
	
	try {
		if ($response = $conn->request($info)) {
		    /* @var $response Metaregistrar\EPP\eppInfoDomainResponse */
		    $d = $response->getDomain();
			echoAndWriteLogfile($domainname,  "Info domain for " . $d->getDomainname() . ":");
		    echoAndWriteLogfile($domainname,  "Created on " 	 . $response->getDomainCreateDate());
		    echoAndWriteLogfile($domainname,  "Last update on "	 . $response->getDomainUpdateDate());
		    echoAndWriteLogfile($domainname,  "Registrant " 	 . $d->getRegistrant());
		    echoAndWriteLogfile($domainname,  "Contact info:");
		    foreach ($d->getContacts() as $contact) {
		        /* @var $contact eppContactHandle */
		        echoAndWriteLogfile($domainname,  "  " . $contact->getContactType() . ": " . $contact->getContactHandle());
		    }
		    echoAndWriteLogfile($domainname,   "Nameserver info:");
		    foreach ($d->getHosts() as $nameserver) {
		        /* @var $nameserver eppHost */
		        echoAndWriteLogfile($domainname,  "  " . $nameserver->getHostname());
		    }
			echoAndWriteLogFile($domainname, "DNSSEC? " . ((bool)$response->hasDnsSec()));

			return $response;
		} else {
		    echoAndWriteLogfile($domainname, "ERROR2");
		}
		return null;

	} catch (eppException $e) {
		echoAndWriteLogfile($domainname, $e->getMessage(), false);
	}
}