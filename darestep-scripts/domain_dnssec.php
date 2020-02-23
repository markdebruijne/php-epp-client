<?php
require('autoloader.php');
require_once('context_loader.php');
require_once('outputert.php');

use Metaregistrar\EPP\eppConnection;
use Metaregistrar\EPP\eppDomain;
use Metaregistrar\EPP\eppInfoDomainRequest;
use Metaregistrar\EPP\eppInfoDomainResponse;
use Metaregistrar\EPP\eppSecdns;
use Metaregistrar\EPP\eppDnssecUpdateDomainRequest;
use Metaregistrar\EPP\eppException;

if ($argc <= 1 || $argc > 3) {
    echo "Usage: signdomaindomain.php <domainname> [--remove]".PHP_EOL;
	echo "<domainname>: Please enter the domain name to be DNSSEC'ed".PHP_EOL;
	echo "--remove: (optional) removes the DNSSEC configuration for the given domain.".PHP_EOL;
	// echo "<targetOrganization>: For example: enecogroup_oxxio".PHP_EOL;
    die();
}

$domainname = $argv[1];
echo "Domain: ".$domainname.PHP_EOL;
$removeDnssec = false;
if($argc == 3 && $argv[2] && strcasecmp($argv[2], "--remove") == 0) {
	$removeDnssec = true;
	echo "DNSSEC: will be REMOVED.".$domainname.PHP_EOL;
}

// $targetOrganization = $argv[2];
// echo "Target organization: ".$targetOrganization.PHP_EOL;

try {
	//  2 functionalities that are implemented
	//  - Add DNSSEC to the domain in case it isn't signed yet.
	//  - Reconfigure the domain so it only contains the DNSSEC data from the file afterwards
	//    > yearly keys will expired and need to be refreshed

    // Please enter your own settings file here under before using this example
	if ($conn = eppConnection::create(getSettingsFileByTld($domainname), true)) {
        $conn->enableDnssec();
        if ($conn->login()) {

            $eppDomain = new eppDomain($domainname);

			// First retrieve info of the domain
			$eppDomainInfo = new eppInfoDomainRequest($eppDomain);
			if ($eppDomainInfoResponse = $conn->request($eppDomainInfo)) {

				if($eppDomainInfoResponse->hasDnsSec()) {
					$keyCounter = $eppDomainInfoResponse->keyCount();
					echo " DNSSEC keys (already) configured. Key count: ".$keyCounter.PHP_EOL;
					
					if($removeDnssec) {
						$removeDnsSecFromDomainResponse = removeDnsSecFromDomain($conn, $eppDomainInfoResponse);
						echo "  DNSSEC removed. Exit.".PHP_EOL;
					} else {
						echo "  Doing nothing. Exit.".PHP_EOL;
					}
					die(); //exit

				} else {
					echo " DNSSEC not (yet / anymore) configured.".PHP_EOL;
				}

				// As prerequisite we need a file with the DNSSEC data to execute one of the following
				$dnssecDataFromFile = importFromFile($domainname);

				if(empty($dnssecDataFromFile)) {
					echo " DNSFILE not found in /dnssec folder";
					die();
				}

				addDnsSecToDomain($conn, $dnssecDataFromFile);
			}
            $conn->logout();
        }
    }
} catch (eppException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

/**
 * @param $domainname string
 */
function getDnsSecFilename($domainname) {
	$dnsFilename = getDnsSecFolder().$domainname.".txt";

	return $dnsFilename;
}

function getDnsSecFolder() {
	$dnsSecFolder = "/../dnssec/";

	return $dnsSecFolder;
}

/**
 * @param $domainname string
 */
function moveDnsSecFilename($domainname) {
	$existingFilename = __DIR__ . getDnsSecFolder() . $domainname . ".txt";
	$newFilename = __DIR__ . getDnsSecFolder() . "completed/" . $domainname . ".txt";

	rename($existingFilename, $newFilename);

	// echo $existingFilename.PHP_EOL;
	// echo $newFilename.PHP_EOL;
}

/**
 * @param $domainname string
 */
function importFromFile($domainname) {
	$dnsFilename = getDnsSecFilename($domainname);

	if(!file_exists(__DIR__ . $dnsFilename)) {
		echo " File not found! " . $dnsFilename .PHP_EOL;
		die();
	}

	$dnsData = file_get_contents(__DIR__ . $dnsFilename, true);

	$elements = explode(" ", $dnsData);

	$object = (object) [
		'domainname'=> rtrim(reset($elements), "."),
		'flags'		=> $elements[4],
		'algorithm'	=> $elements[6],
		'publicKey' => ltrim(rtrim($elements[7], ")"), "("), // Brackets are not compatible with EPP :(
		'keytag'	=> rtrim(end($elements))
		];
	echo " DNSSEC data imported OK".PHP_EOL;
	
	// var_dump($object);
	// echo $domainname.PHP_EOL;
	// echo $object->domainname.PHP_EOL;

	if(strcasecmp($domainname, $object->domainname) != 0) {
		echo "DNSSEC file contents contains values for wrong domain!".PHP_EOL;
		die();
	}

	return $object;
}

function addDnsSecToDomain($conn, $dnssecDataFromFile) {

	$domainname = $dnssecDataFromFile->domainname;
	$eppDomain = new eppDomain($domainname);

	$dnssecFlags	= $dnssecDataFromFile->flags;
	$dnssecAlg		= $dnssecDataFromFile->algorithm;
	$dnssecPubKey	= $dnssecDataFromFile->publicKey;
	$dnssecKeyId	= $dnssecDataFromFile->keytag;

	$sec = new eppSecdns();
	$sec->setKey($dnssecFlags, $dnssecAlg, $dnssecPubKey);
	$eppDomain->addSecdns($sec);

	// var_dump($sec);

	$eppDomainUpdateRequest = new eppDnssecUpdateDomainRequest($domainname, $eppDomain);
	if ($eppDomainUpdateResponse = $conn->request($eppDomainUpdateRequest)) { // getting result implies that update succeeded.
	    /* @var $response Metaregistrar\EPP\eppUpdateDomainResponse */
	    echo " DNSSEC added with KeyId: ".$dnssecKeyId.PHP_EOL;

		// Move DNSSEC file into archive folder
		moveDnsSecFilename($domainname);
		echo "  (imported file moved to archive)".PHP_EOL;

		if($refreshedEppDomainInfoResponse = $conn->request($eppDomainInfo)) {

			if($refreshedEppDomainInfoResponse->hasDnsSec()) {
				$dnssecData = $refreshedEppDomainInfoResponse->getKeydata();

				echo " DNSSEC keys: " . count($dnssecData) .PHP_EOL;

				foreach ($dnssecData as $secdns) {
				    // var_dump($secdns);
					echo "  >  keyid:" . $secdns->getKeytag() . " => " . substr($secdns->getPubkey(), 0, 25) . " [..] " . PHP_EOL;
				}
			}
		}
	}
}

function removeDnsSecFromDomain($conn, $eppDomainInfoResponse) {

	if($eppDomainInfoResponse) {
		// This is messy. We have an infoResponse of a particular domain(object)
		// We need to pass that into the eppDnssecUpdateDomainRequest constructor, together 
		//  with an eppDomain object containing the keys to remove
		$eppDomain = $eppDomainInfoResponse->getDomain();

		// the eppDomainInfoResponse already has been checked for Dnssec
		// so we can retrieve the key data directly. 
		$dnssecKeyData = $eppDomainInfoResponse->getKeydata();
		// reconstruct the dnssec keys to the eppDomain-object

		if (is_array($dnssecKeyData) && (count($dnssecKeyData)>0)) {
			
			foreach ($dnssecKeyData as $dnssecItem) {
				/* @var eppSecdns $dnssec */
				$eppDomain->addSecdns($dnssecItem);
			}

			// The eppDnssecUpdateDomainRequest can be called with either Add/Remove/Update parameter filled
			$eppDomainUpdateWithDnssecModificationsRequest = new eppDnssecUpdateDomainRequest($eppDomain, null, $eppDomain, null);

			// IMPORTANT HACK
			// This EPP implementation uses an "Update Domain Request" which can optionally include DNSSEC changes
			//  But the implementation is that once you send in 'remove dnssec' instructions, that also handles/NS removal will
			//  take place. It expects that you remove AND (re)add the new handles/NS you want. But as we only want to
			//  recycle the DNSSEC keys, we clear out these domain-level-instructions.
			$domainLevelRemovalInstructions 	= $eppDomainUpdateWithDnssecModificationsRequest->getElementsByTagName('domain:rem');
			if(count($domainLevelRemovalInstructions) > 0) {
				$domainLevelRemovalInstruction = $domainLevelRemovalInstructions->item(0);
				$domainLevelRemovalInstruction	->parentNode->removeChild($domainLevelRemovalInstruction);
			}

			$domainLevelChangeInstructions = $eppDomainUpdateWithDnssecModificationsRequest->getElementsByTagName('domain:chg');
			if(count($domainLevelChangeInstructions) > 0) {
				$domainLevelChangeInstruction = $domainLevelChangeInstructions->item(0);
				$domainLevelChangeInstruction ->parentNode->removeChild($domainLevelChangeInstruction);
			}

			$domainLevelAddInstructions = $eppDomainUpdateWithDnssecModificationsRequest->getElementsByTagName('domain:add');
			if(count($domainLevelAddInstructions) > 0) {
				$domainLevelAddInstruction = $domainLevelAddInstructions->item(0);
				$domainLevelAddInstruction ->parentNode->removeChild($domainLevelAddInstruction);
			}

			if ($eppDomainUpdateWithDnssecModificationsResponse = $conn->request($eppDomainUpdateWithDnssecModificationsRequest)) {	
				return $eppDomainUpdateWithDnssecModificationsResponse;
			}
		} else {
			echo "mismatch".PHP_EOL;
			die();
		}
	}	
}