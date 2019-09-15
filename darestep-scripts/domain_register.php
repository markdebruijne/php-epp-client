<?php
require('autoloader.php');
require_once('context_loader.php');
require_once('outputert.php');

use Metaregistrar\EPP\eppConnection;
use Metaregistrar\EPP\eppException;
use Metaregistrar\EPP\eppContactHandle;
use Metaregistrar\EPP\eppCheckRequest;
use Metaregistrar\EPP\eppCheckDomainRequest;
use Metaregistrar\EPP\eppCheckDomainResponse;
use Metaregistrar\EPP\eppContactPostalInfo;
use Metaregistrar\EPP\eppContact;
use Metaregistrar\EPP\eppCreateContactRequest;
use Metaregistrar\EPP\eppHost;
use Metaregistrar\EPP\eppCreateHostRequest;
use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppCreateDomainRequest;

/*
 * This sample script registers a domain name within your account
 * 
 * The nameservers of metaregistrar are used as nameservers
 * In this scrips, the same contact id is used for registrant, admin-contact, tech-contact and billing contact
 * Recommended usage is that you use a tech-contact and billing contact of your own, and set registrant and admin-contact to the domain name owner or reseller.
 */


if ($argc <= 1 || $argc > 3)
{
    echo "Usage: domain_info.php <domainname> <targetOrganization>\n";
	echo "<domainname>: Please enter the domain name to registered".PHP_EOL;
	echo "<targetOrganization>: For example: enecogroup_oxxio".PHP_EOL;
	echo "---------------------".PHP_EOL;
	die();
}

$domainname = $argv[1];
$targetOrganization = $argv[2];

echo "Registering $domainname\n";
try {
// Please enter your own settings file here under before using this example
    if ($conn = eppConnection::create('settings.ini', true)) {
        
        // Determine which context_file to use: contains registrant, handles and name server
        $contextDictionary = loadContext($conn, $domainname, $targetOrganization);
        
        echoAndWriteLogfile($domainname, "Received instructions to register: " . $domainname . " @ " . date('Y-m-d H:i:s'));
        
        // Connect to the EPP server
        if ($conn->login()) {

            // First check whether the domain name is (still/already) available
            $isAvailable = isDomainAvailable($conn, $domainname);

			if(!$isAvailable) {
				echoAndWriteLogfile($domainname, "Registration aborted: not available (yet/anymore).");
			} else {
				// Register based on the handles and nameservers a loaded from the context file
				$nameservers = getContextNameServers($contextDictionary);
				createdomain($conn, $domainname, $contextDictionary["registrant"], $contextDictionary["admin1"], $contextDictionary["tech1"], $contextDictionary["billing1"], $nameservers);
			}
            
            $conn->logout();
        }
    }
} catch (eppException $e) {
    echo $e->getMessage();
}

function isDomainAvailable($conn, $domainname) {
        // Create request to be sent to EPP service
        $availabilityCheck = new eppCheckDomainRequest(array($domainname)); // seems to expects array as input :(
        // Write request to EPP service, read and check the results
        if ($availabilityResponse = $conn->request($availabilityCheck)) {

            // Response is an array with the domainname that there checked, each record is a dictionary.
            // - domainname
            // - available
            // - reason
            $availableResult = $availabilityResponse->getCheckedDomains();

            $isAvailable = $availableResult[0]["available"]; // we know we put in one, so we should have exact one result.
            echoAndWriteLogfile($domainname, "is available: " .var_export($isAvailable, true)); // print bool as string
            if($isAvailable === false && array_key_exists("reason", $availableResult)) {
                echoAndWriteLogfile($domainname, " reason not available: ". $availableResult["reason"]);
            }

			return $isAvailable;
        }
}

function checkcontact($conn, $contactid) {
    /* @var $conn Metaregistrar\EPP\eppConnection */
    try {
        $check = new eppCheckRequest(new eppContactHandle($contactid));
        if ($response = $conn->request($check)) {
            /* @var $response Metaregistrar\EPP\eppCheckResponse */
            $checks = $response->getCheckedContacts();
            foreach ($checks as $contact => $check) {
                echo "Contact $contact " . ($check ? 'does not exist' : 'exists') . "\n";
            }
        }
    } catch (eppException $e) {
        echo $e->getMessage() . "\n";
    }
}


function createcontact($conn, $email, $telephone, $name, $organization, $address, $postcode, $city, $country) {
    /* @var $conn Metaregistrar\EPP\eppConnection */
    try {
        $contactinfo = new eppContact(new eppContactPostalInfo($name, $city, $country, $organization, $address, null, $postcode, Metaregistrar\EPP\eppContact::TYPE_LOC), $email, $telephone);
        $contactinfo->setPassword('fubar');
        $contact = new eppCreateContactRequest($contactinfo);
        if ($response = $conn->request($contact)) {
            /* @var $response Metaregistrar\EPP\eppCreateContactResponse */
            echo "Contact created on " . $response->getContactCreateDate() . " with id " . $response->getContactId() . "\n";
            return $response->getContactId();
        }
    } catch (eppException $e) {
        echo $e->getMessage() . "\n";
    }
    return null;
}


/**
 * @param $conn eppConnection
 * @param $hosts
 * @return bool|null
 */
function checkhosts($conn, $hosts) {
    try {
        $checkhost = array();
        foreach ($hosts as $host) {
            $checkhost[] = new eppHost($host);
        }
        $check = new eppCheckRequest($checkhost);
        if ($response = $conn->request($check)) {
            /* @var $response Metaregistrar\EPP\eppCheckResponse */
            $checks = $response->getCheckedHosts();
            $allchecksok = true;
            foreach ($checks as $hostname => $check) {
                echo "$hostname " . ($check ? 'does not exist' : 'exists') . "\n";
                if ($check) {
                    $allchecksok = false;
                }
            }
            return $allchecksok;
        }
    } catch (eppException $e) {
        echo $e->getMessage() . "\n";
    }
    return null;
}

/**
 * @param eppConnection $conn
 * @param string $hostname
 * @param string $ipaddress
 */
function createhost($conn, $hostname, $ipaddress=null) {

    try {
        $create = new eppCreateHostRequest(new eppHost($hostname,$ipaddress));
        if ($response = $conn->request($create)) {
            /* @var $response Metaregistrar\EPP\eppCreateHostResponse */
            echo "Host created on " . $response->getHostCreateDate() . " with name " . $response->getHostName() . "\n";
        }
    } catch (eppException $e) {
        echo $e->getMessage() . "\n";
    }
}


/**
 * @param eppConnection $conn
 * @param string $domainname
 * @param string $registrant
 * @param string $admincontact
 * @param string $techcontact
 * @param string $billingcontact
 * @param array $nameservers
 */
function createdomain($conn, $domainname, $registrant, $admincontact, $techcontact, $billingcontact, $nameservers) {
    /* @var $conn Metaregistrar\EPP\eppConnection */
    try {
        $domain = new eppDomain($domainname, $registrant);
        $domain->setRegistrant(new eppContactHandle($registrant));
        $domain->addContact(new eppContactHandle($admincontact, eppContactHandle::CONTACT_TYPE_ADMIN));
        $domain->addContact(new eppContactHandle($techcontact, eppContactHandle::CONTACT_TYPE_TECH));
		if($billingcontact) {
			$domain->addContact(new eppContactHandle($billingcontact, eppContactHandle::CONTACT_TYPE_BILLING));
		}
        $domain->setAuthorisationCode('rand0m');
        if (is_array($nameservers)) {
            foreach ($nameservers as $nameserver) {
                $domain->addHost(new eppHost($nameserver));
            }
        }
        $create = new eppCreateDomainRequest($domain);
        if ($response = $conn->request($create)) {
            /* @var $response Metaregistrar\EPP\eppCreateDomainResponse */
            echo "Domain " . $response->getDomainName() . " created on " . $response->getDomainCreateDate() . ", expiration date is " . $response->getDomainExpirationDate() . "\n";
        }
    } catch (eppException $e) {
        echo $e->getMessage() . "\n";
    }
}
